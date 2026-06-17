<?php
// Deteksi jika ini adalah request API
if (isset($_GET['action'])) {
    include("config.php");
    include_once("classes/UrlRewriter.php");
    include("classes/Crawler.php");
    include("classes/DomDocumentParser.php");

    // Persiapan Streaming Response
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Disable NGINX buffering jika ada
    
    // Matikan semua output buffering PHP
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    function out($msg) {
        echo $msg . "\n";
        @ob_flush();
        @flush();
    }

    $alreadyCrawled = array();
    $crawling = array();
    $alreadyFoundImages = array();

    function linkExists($url) {
        global $con;
        $query = $con->prepare("SELECT * FROM cari_sites WHERE url = :url");
        $query->bindParam(":url", $url);
        $query->execute();
        return $query->rowCount() != 0;
    }

    function imageExists($src) {
        global $con;
        $query = $con->prepare("SELECT * FROM cari_images WHERE imageUrl = :src");
        $query->bindParam(":src", $src);
        $query->execute();
        return $query->rowCount() != 0;
    }

    function insertLink($url, $title, $description, $keywords) {
        global $con;
        $query = $con->prepare("INSERT INTO cari_sites(url, title, description, keywords) VALUES(:url, :title, :description, :keywords)");
        $query->bindParam(":url", $url);
        $query->bindParam(":title", $title);
        $query->bindParam(":description", $description);
        $query->bindParam(":keywords", $keywords);
        return $query->execute();
    }

    function insertImage($url, $src, $alt, $title) {
        global $con;
        $query = $con->prepare("INSERT INTO cari_images(siteUrl, imageUrl, alt, title, clicks, broken) VALUES(:siteUrl, :imageUrl, :alt, :title, 0, 0)");
        $query->bindParam(":siteUrl", $url);
        $query->bindParam(":imageUrl", $src);
        $query->bindParam(":alt", $alt);
        $query->bindParam(":title", $title);
        return $query->execute();
    }

    function createLink($src, $url) {
        $parsed_url = parse_url($url);
        $scheme = isset($parsed_url["scheme"]) ? $parsed_url["scheme"] : "http";
        $host = isset($parsed_url["host"]) ? $parsed_url["host"] : "";
        $path = isset($parsed_url["path"]) ? $parsed_url["path"] : "/";

        if(substr($src, 0, 2) == "//") 
            $src =  $scheme . ":" . $src;
        else if(substr($src, 0, 1) == "/") 
            $src = $scheme . "://" . $host . $src;
        else if(substr($src, 0, 2) == "./") 
            $src = $scheme . "://" . $host . dirname($path) . substr($src, 1);
        else if(substr($src, 0, 3) == "../") 
            $src = $scheme . "://" . $host . "/" . $src;
        else if(substr($src, 0, 5) != "https" && substr($src, 0, 4) != "http") {
            $dir = rtrim(is_dir($path) || substr($path, -1) == '/' ? $path : dirname($path), "/");
            $src = $scheme . "://" . $host . $dir . "/" . $src;
        }
        return $src;
    }

    function getDetails($url) {
        global $alreadyFoundImages;

        $parser = new DomDocumentParser($url);
        $titleArray = $parser->getTitleTags();
        if(sizeof($titleArray) == 0 || $titleArray->item(0) == NULL) return;

        $title = $titleArray->item(0)->nodeValue;
        $title = str_replace("\n", "", $title);
        if($title == "") return;

        $description = "";
        $keywords = "";
        $metasArray = $parser->getMetatags();
        foreach($metasArray as $meta) {
            if($meta->getAttribute("name") == "description")
                $description = $meta->getAttribute("content");
            if($meta->getAttribute("name") == "keywords")
                $keywords = $meta->getAttribute("content");
        }	
        $description = str_replace("\n", "", $description);
        $keywords = str_replace("\n", "", $keywords);

        if(linkExists($url)) out("[INFO] $url already exists");
        else if(insertLink($url, $title, $description, $keywords)) out("[SUCCESS] Inserted Site: $url");
        else out("[ERROR] Failed to insert Site: $url");

        $imageArray = $parser->getImages();
        foreach($imageArray as $image) {
            $src = $image->getAttribute("src");
            $alt = $image->getAttribute("alt");
            $title = $image->getAttribute("title");

            if(!$title && !$alt) continue;

            $src = createLink($src, $url);
            if(!in_array($src, $alreadyFoundImages)) {
                $alreadyFoundImages[] = $src;
                if(imageExists($src)) {
                    // out("[INFO] $src already exists");
                }
                else if(insertImage($url, $src, $alt, $title)) out("[SUCCESS] Inserted Image: $src");
                else out("[ERROR] Failed to insert Image: $src");
            }
        }
    }

    function followLinks($url) {
        global $alreadyCrawled;
        global $crawling;

        $parser = new DomDocumentParser($url);
        $linkList = $parser->getLinks();

        foreach($linkList as $link) {
            $href = $link->getAttribute("href");
            if(strpos($href, "#") !== false) continue;
            else if(substr($href, 0, 11) == "javascript:") continue;

            $href = createLink($href, $url);

            if(!in_array($href, $alreadyCrawled)) {
                $alreadyCrawled[] = $href;
                $crawling[] = $href;
                getDetails($href);
            }
        }
        array_shift($crawling);
        foreach($crawling as $site) followLinks($site);
    }

    function scanLocalDirectory($dir, $baseUrl, $useQueryFormat) {
        if (!is_dir($dir)) {
            out("[ERROR] Direktori lokal tidak ditemukan: $dir");
            return;
        }

        $dir = rtrim($dir, '/\\');
        $baseUrl = rtrim($baseUrl, '/');

        out("[INFO] Memulai pemindaian direktori lokal: $dir");
        
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $count = 0;

        $parsed = parse_url($baseUrl);
        $domain = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $basePath = ltrim($parsed['path'] ?? '', '/');

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            
            // Abaikan file yang berada di dalam direktori '_files'
            $normalizedPath = str_replace('\\', '/', $file->getPathname());
            if (strpos($normalizedPath, '/_files') !== false) {
                continue;
            }


            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowedExts)) {
                $filePath = $file->getPathname();
                $relativePath = str_replace($dir, "", $filePath);
                $relativePath = str_replace('\\', '/', $relativePath);
                
                $publicUrl = rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
                
                $folderPath = trim(dirname($relativePath), '/\\');
                $folderPath = $folderPath == '.' ? '' : $folderPath;
                $fullPath = trim($basePath . '/' . $folderPath, '/');
                $filenameExt = basename($filePath);

                if ($useQueryFormat == "true") {
                    $baseSiteUrl = empty($fullPath) ? $domain . '/' : $domain . '/?' . $fullPath;
                    $siteUrl = $baseSiteUrl . '#pid=' . $filenameExt;
                } else {
                    $siteUrl = empty($fullPath) ? $domain . '/' : $domain . '/' . $fullPath . '/';
                }

                $filenameNoExt = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $title = ucwords(str_replace(['_', '-'], ' ', $filenameNoExt));
                $alt = $title;

                $publicUrl = UrlRewriter::normalize($publicUrl);
                $siteUrl = UrlRewriter::normalize($siteUrl);

                if (!imageExists($publicUrl)) {
                    if (insertImage($siteUrl, $publicUrl, $alt, $title)) {
                        out("[SUCCESS] + $title");
                        $count++;
                    } else {
                        out("[ERROR] Gagal menyimpan $publicUrl");
                    }
                }
            }
        }
        out("[INFO] SELESAI! Berhasil menambahkan $count gambar dari direktori lokal.");
    }

    function scanLocalDirectoryForSites($dir, $baseUrl, $useQueryFormat) {
        if (!is_dir($dir)) {
            out("[ERROR] Direktori lokal tidak ditemukan: $dir");
            return;
        }

        $dir = rtrim($dir, '/\\');
        $baseUrl = rtrim($baseUrl, '/');

        out("[INFO] Mulai Crawling Direktori: $dir");
        
        $dirCount = 0;
        $imgCount = 0;

        // Kumpulkan semua file dan folder (termasuk root)
        $items = array($dir);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->getFilename() == '.' || $file->getFilename() == '..') continue;
            
            // Abaikan direktori bernama '_files' beserta seluruh isinya
            $normalizedPath = str_replace('\\', '/', $file->getPathname());
            if (strpos($normalizedPath, '/_files') !== false) {
                continue;
            }

            $items[] = $file->getPathname();
        }

        $parsed = parse_url($baseUrl);
        $domain = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        $basePath = ltrim($parsed['path'] ?? '', '/');

        foreach ($items as $itemPath) {
            $relativePath = str_replace($dir, "", $itemPath);
            $relativePath = str_replace('\\', '/', $relativePath);
            
            if (is_dir($itemPath)) {
                // Lewati Proses Direktori (Katalog tidak lagi dimasukkan ke database)
                continue;
            } else {
                // Proses File Gambar -> cari_images DAN cari_sites
                $ext = strtolower(pathinfo($itemPath, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $publicUrl = rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
                    
                    $filenameExt = basename($itemPath);
                    $filenameNoExt = pathinfo($itemPath, PATHINFO_FILENAME);
                    $title = ucwords(str_replace(['_', '-'], ' ', $filenameNoExt));
                    $keywords = str_replace(' ', ', ', strtolower($title));
                    
                    // Format ulang kode produk dalam kurung. Contoh: "(fg, 24948)" menjadi "FG-24948"
                    $keywords = preg_replace_callback('/\(([a-z]+),\s*([a-z0-9]+)\)/i', function($matches) {
                        return strtoupper($matches[1]) . '-' . strtoupper($matches[2]);
                    }, $keywords);

                    $description = $title;

                    $parentPath = trim(dirname($relativePath), '/\\');
                    $parentPath = $parentPath == '.' ? '' : $parentPath;
                    $fullParentPath = trim($basePath . '/' . $parentPath, '/');

                    if ($useQueryFormat == "true") {
                        $baseSiteUrl = empty($fullParentPath) ? $domain . '/' : $domain . '/?' . $fullParentPath;
                        $linkUrlForSite = $baseSiteUrl . '#pid=' . $filenameExt;
                        $siteUrlForImage = $linkUrlForSite;
                    } else {
                        $baseSiteUrl = empty($fullParentPath) ? $domain . '/' : $domain . '/' . $fullParentPath . '/';
                        $linkUrlForSite = $publicUrl;
                        $siteUrlForImage = $baseSiteUrl;
                    }

                    $publicUrl = UrlRewriter::normalize($publicUrl);
                    $linkUrlForSite = UrlRewriter::normalize($linkUrlForSite);
                    $siteUrlForImage = UrlRewriter::normalize($siteUrlForImage);

                    // 1. Masukkan ke cari_sites (Tab Semua)
                    if (!linkExists($linkUrlForSite)) {
                        if (insertLink($linkUrlForSite, $title, $description, $keywords)) {
                            out("[SUCCESS] Ditemukan: $title");
                            $dirCount++;
                        }
                    } else {
                        out("[INFO] Skip: <span class='text-success'>$title</span> sudah ada");
                    }

                    // 2. Masukkan ke cari_images (Tab Gambar)
                    $alt = $title;

                    if (!imageExists($publicUrl)) {
                        if (insertImage($siteUrlForImage, $publicUrl, $alt, $title)) {
                            // Cukup tampilkan satu log success agar terminal tidak double
                            $imgCount++;
                        } else {
                            out("[ERROR] Gagal menyimpan gambar $publicUrl");
                        }
                    }
                }
            }
        }
        out("[INFO] SELESAI! Berhasil menambahkan $dirCount tautan ke cari_sites dan $imgCount gambar ke cari_images.");
    }

    if ($_GET['action'] == 'crawl_web') {
        $url = $_POST['url'] ?? '';
        $base = $_POST['baseurl'] ?? '';
        $queryFormat = "true"; // Selalu gunakan Query String untuk direktori lokal
        
        if (is_dir($url)) {
            if (empty($base)) {
                out("[ERROR] Base Public URL harus diisi untuk memindai direktori lokal!");
            } else {
                scanLocalDirectoryForSites($url, $base, $queryFormat);
            }
        } else if (substr($url, 0, 4) === 'http') {
            out("[INFO] Memulai Web Crawling pada: $url");
            followLinks($url);
            out("[INFO] Crawling Selesai.");
        } else {
            out("[ERROR] Target tidak valid. Masukkan URL Web (http://...) atau jalur direktori fisik.");
        }
    } else if ($_GET['action'] == 'crawl_local') {
        $dir = $_POST['url'] ?? '';
        $base = $_POST['baseurl'] ?? '';
        $queryFormat = "true"; // Selalu gunakan Query String untuk direktori lokal
        
        if (empty($base)) {
            out("[ERROR] Base Public URL harus diisi!");
        } else {
            scanLocalDirectory($dir, $base, $queryFormat);
        }
    }
    
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Crawler Engine</title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon/favicon.ico">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap 5.3 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Vue 3 CDN -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; height: 100vh; overflow: hidden; display: flex; align-items: center; justify-content: center; margin: 0; }
        .crawl-card { width: 100%; max-width: 1200px; height: 92vh; background: #fff; padding: 0; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #eaeaea; overflow: hidden; }
        .terminal { background-color: #1e1e1e; color: #00ff00; font-family: 'Courier New', Courier, monospace; padding: 25px; height: 100%; overflow-y: auto; font-size: 13px; line-height: 1.5; border-radius: 0; }
        .terminal span.error { color: #ff4c4c; }
        .terminal span.info { color: #00bfff; }
        .terminal span.success { color: #00ff00; }
        .nav-pills .nav-link { color: #555; border-radius: 30px; padding: 10px 25px; font-weight: bold; transition: all 0.3s; }
        .nav-pills .nav-link.active { background-color: #0d6efd; color: white; box-shadow: 0 4px 10px rgba(13,110,253,0.3); }
        .nav-pills .nav-link:hover:not(.active) { background-color: #e9ecef; }
        
        @media (min-width: 768px) {
            .border-end-md { border-right: 1px solid #eaeaea; }
            .terminal { border-radius: 0 16px 16px 0; }
        }
        @media (max-width: 767px) {
            body { height: auto; overflow: auto; display: block; padding: 20px 0; }
            .crawl-card { height: auto; min-height: auto; }
            .col-md-6.h-100 { height: auto !important; }
            .terminal { border-radius: 0 0 16px 16px; height: 400px !important; }
        }
    </style>
</head>
<body>
    <div id="app" class="container-fluid px-md-5 w-100">
        <div class="crawl-card mx-auto">
            <div class="row g-0 h-100">
                <!-- Kolom Kiri -->
                <div class="col-md-6 border-end-md h-100" style="overflow-y: auto;">
                    <div class="p-4 p-md-5 d-flex flex-column justify-content-center" style="min-height: 100%;">
                <div class="text-center mb-4">
                    <img src="assets/images/doogleLogo.png" alt="Doogle" height="40">
                    <h4 class="mt-3 fw-bold text-dark">Crawler Engine</h4>
                    <p class="text-muted small">Mesin crawling situs dan direktori lokal</p>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary mt-1 rounded-pill px-3">Kembali ke Beranda</a>
                </div>

                <!-- Form Utama: Crawler Sites & Images -->
                <div class="mt-2">
                    <div class="crawl-form-container">
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold text-secondary">Lokasi Direktori Lokal</label>
                                <input type="text" class="form-control form-control-lg bg-light" v-model="webForm.url" placeholder="Contoh: /var/www/FOTO" required :disabled="isCrawling">
                                <div class="form-text">Mengindeks Direktori Foto Produk</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm flex-grow-1" v-if="!isCrawling" @click="submitWeb">
                                Mulai
                            </button>
                            <button type="button" class="btn btn-danger btn-lg rounded-pill fw-bold shadow-sm flex-grow-1" v-if="isCrawling" @click="stopCrawling">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                Stop
                            </button>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-danger rounded-pill fw-bold shadow-sm flex-grow-1" :disabled="isCrawling || isResetting" @click="resetDB">
                                <span v-if="isResetting" class="spinner-border spinner-border-sm me-2"></span>
                                🗑️ Reset DB
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

                <!-- Kolom Kanan: Terminal Output Live Streaming -->
                <div class="col-md-6 h-100">
                    <div class="terminal" ref="terminalBox">
                        <div v-if="logs.length === 0" class="text-muted">
                            <span style="color:#555;">doogleBot@server:~# Menunggu perintah...</span>
                        </div>
                        <div v-for="(log, index) in logs" :key="index" v-html="formatLog(log)"></div>
                        
                        <!-- Typing cursor animation -->
                        <div v-if="isCrawling" class="mt-2"><span class="spinner-grow spinner-grow-sm text-success" role="status"></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const { createApp } = Vue

        createApp({
            data() {
                return {
                    activeTab: 'gambar', // Default buka tab gambar sesuai kebutuhan terbaru
                    isCrawling: false,
                    isResetting: false,
                    abortController: null,
                    logs: [],
                    webForm: { 
                        url: '/var/www/FOTO',
                        baseurl: 'http://192.168.1.17:81',
                        queryFormat: true
                    },
                    localForm: { 
                        url: '/var/www/FOTO', 
                        baseurl: 'http://192.168.1.17:81', 
                        queryFormat: true 
                    }
                }
            },
            methods: {
                formatLog(text) {
                    // Beri warna pada keyword tertentu
                    if (text.startsWith('[ERROR]')) return `<span class="error">${text}</span>`;
                    if (text.startsWith('[INFO]')) return `<span class="info">${text}</span>`;
                    if (text.startsWith('[SUCCESS]')) return `<span class="success">${text}</span>`;
                    return text; // fallback normal
                },
                scrollToBottom() {
                    // Fungsi auto-scroll terminal ke bawah
                    this.$nextTick(() => {
                        const box = this.$refs.terminalBox;
                        if(box) box.scrollTop = box.scrollHeight;
                    });
                },
                async streamResponse(url, formData) {
                    this.isCrawling = true;
                    this.logs = [];
                    this.logs.push('[INFO] Mengirim request ke Doogle Engine...');

                    this.abortController = new AbortController();
                    const signal = this.abortController.signal;

                    try {
                        // Menggunakan fetch untuk mendapatkan ReadableStream
                        const response = await fetch(url, {
                            method: 'POST',
                            body: formData,
                            signal: signal
                        });

                        if (!response.body) {
                            this.logs.push('[ERROR] Fetch API Streaming tidak didukung oleh browser ini.');
                            return;
                        }

                        const reader = response.body.getReader();
                        const decoder = new TextDecoder('utf-8');
                        let done = false;

                        // Membaca stream secara berkala (chunk demi chunk)
                        while (!done) {
                            const { value, done: readerDone } = await reader.read();
                            done = readerDone;
                            
                            if (value) {
                                const chunk = decoder.decode(value, { stream: true });
                                const lines = chunk.split('\n'); // pisahkan baris
                                
                                lines.forEach(line => {
                                    if (line.trim() !== '') {
                                        this.logs.push(line.trim());
                                        this.scrollToBottom();
                                    }
                                });
                            }
                        }
                        this.logs.push('[SELESAI] Proses Crawling ditutup');
                    } catch (e) {
                        if (e.name === 'AbortError') {
                            this.logs.push('[INFO] Proses Crawling dihentikan oleh pengguna.');
                        } else {
                            this.logs.push(`[ERROR] Terputus dari server: ${e.message}`);
                        }
                    } finally {
                        this.isCrawling = false;
                        this.abortController = null;
                        this.scrollToBottom();
                    }
                },
                stopCrawling() {
                    if (this.abortController) {
                        this.abortController.abort();
                    }
                },
                async resetDB() {
                    const result = await Swal.fire({
                        title: 'Peringatan Berbahaya!',
                        text: "Yakin ingin mereset Database? Semua data gambar dan riwayat pencarian akan dihapus secara permanen!",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Ya, Hapus Permanen!',
                        cancelButtonText: 'Batal'
                    });

                    if (!result.isConfirmed) return;
                    
                    this.isResetting = true;
                    this.isCrawling = true; // Lock UI Start button
                    this.logs = [];
                    this.logs.push('[INFO] Menghubungi peladen untuk inisiasi Reset Database...');
                    this.scrollToBottom();

                    try {
                        const response = await fetch('reset_db.php?stream=1', { method: 'GET' });

                        if (!response.body) {
                            this.logs.push('[ERROR] Fetch API Streaming tidak didukung oleh browser ini.');
                            return;
                        }

                        const reader = response.body.getReader();
                        const decoder = new TextDecoder('utf-8');
                        let done = false;

                        while (!done) {
                            const { value, done: readerDone } = await reader.read();
                            done = readerDone;
                            
                            if (value) {
                                const chunk = decoder.decode(value, { stream: true });
                                const lines = chunk.split('\n');
                                
                                lines.forEach(line => {
                                    if (line.trim() !== '') {
                                        this.logs.push(line.trim());
                                        this.scrollToBottom();
                                    }
                                });
                            }
                        }
                    } catch (e) {
                        this.logs.push(`[ERROR] Terputus dari server: ${e.message}`);
                    } finally {
                        this.isResetting = false;
                        this.isCrawling = false;
                        this.scrollToBottom();
                    }
                },
                submitWeb() {
                    let fd = new FormData();
                    fd.append('url', this.webForm.url);
                    fd.append('baseurl', this.webForm.baseurl);
                    fd.append('queryFormat', this.webForm.queryFormat ? 'true' : 'false');
                    this.streamResponse('crawl.php?action=crawl_web', fd);
                },
                submitLocal() {
                    let fd = new FormData();
                    fd.append('url', this.localForm.url);
                    fd.append('baseurl', this.localForm.baseurl);
                    fd.append('queryFormat', this.localForm.queryFormat ? 'true' : 'false');
                    this.streamResponse('crawl.php?action=crawl_local', fd);
                }
            }
        }).mount('#app')
    </script>
</body>
</html>