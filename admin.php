<?php
include("config.php");
include_once("classes/UrlRewriter.php");

// ==========================================
// BACKEND API LOGIC
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    try {
        if ($action === 'get_sites') {
            $stmt = $con->prepare("SELECT * FROM cari_sites WHERE deleted_at IS NULL ORDER BY title ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($data as &$row) {
                if (isset($row['url'])) $row['url'] = UrlRewriter::rewrite($row['url']);
            }
            echo json_encode(["status" => "success", "data" => $data]);
            exit;
        }

        if ($action === 'get_images') {
            $stmt = $con->prepare("SELECT * FROM cari_images WHERE deleted_at IS NULL ORDER BY title ASC");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($data as &$row) {
                if (isset($row['imageUrl'])) $row['imageUrl'] = UrlRewriter::rewrite($row['imageUrl']);
                if (isset($row['siteUrl'])) $row['siteUrl'] = UrlRewriter::rewrite($row['siteUrl']);
            }
            echo json_encode(["status" => "success", "data" => $data]);
            exit;
        }

        if ($action === 'delete_site') {
            $data = json_decode(file_get_contents("php://input"), true);
            $stmt = $con->prepare("UPDATE cari_sites SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':id' => $data['id']]);
            echo json_encode(["status" => "success"]);
            exit;
        }

        if ($action === 'delete_image') {
            $data = json_decode(file_get_contents("php://input"), true);
            $stmt = $con->prepare("UPDATE cari_images SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':id' => $data['id']]);
            echo json_encode(["status" => "success"]);
            exit;
        }

        if ($action === 'save_site') {
            $data = json_decode(file_get_contents("php://input"), true);
            if (!empty($data['id'])) {
                $stmt = $con->prepare("UPDATE cari_sites SET url=:url, title=:title, description=:description, keywords=:keywords, clicks=:clicks WHERE id=:id");
                $stmt->execute([
                    ':url' => $data['url'], ':title' => $data['title'],
                    ':description' => $data['description'], ':keywords' => $data['keywords'],
                    ':clicks' => $data['clicks'] ?? 0, ':id' => $data['id']
                ]);
            } else {
                $stmt = $con->prepare("INSERT INTO cari_sites (url, title, description, keywords, clicks) VALUES (:url, :title, :description, :keywords, :clicks)");
                $stmt->execute([
                    ':url' => $data['url'], ':title' => $data['title'],
                    ':description' => $data['description'], ':keywords' => $data['keywords'],
                    ':clicks' => $data['clicks'] ?? 0
                ]);
            }
            echo json_encode(["status" => "success"]);
            exit;
        }

        if ($action === 'save_image') {
            $data = json_decode(file_get_contents("php://input"), true);
            if (!empty($data['id'])) {
                $stmt = $con->prepare("UPDATE cari_images SET siteUrl=:siteUrl, imageUrl=:imageUrl, title=:title, alt=:alt, clicks=:clicks, broken=:broken WHERE id=:id");
                $stmt->execute([
                    ':siteUrl' => $data['siteUrl'], ':imageUrl' => $data['imageUrl'],
                    ':title' => $data['title'], ':alt' => $data['alt'],
                    ':clicks' => $data['clicks'] ?? 0, ':broken' => $data['broken'] ?? 0, ':id' => $data['id']
                ]);
            } else {
                $stmt = $con->prepare("INSERT INTO cari_images (siteUrl, imageUrl, title, alt, clicks, broken) VALUES (:siteUrl, :imageUrl, :title, :alt, :clicks, :broken)");
                $stmt->execute([
                    ':siteUrl' => $data['siteUrl'], ':imageUrl' => $data['imageUrl'],
                    ':title' => $data['title'], ':alt' => $data['alt'],
                    ':clicks' => $data['clicks'] ?? 0, ':broken' => $data['broken'] ?? 0
                ]);
            }
            echo json_encode(["status" => "success"]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Vue.js 3 -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background-color: #ffffff; /* Putih Bersih */
            color: #333333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 80px; /* Jarak untuk Fixed Navbar */
        }
        
        /* Navbar Fixed */
        .navbar-custom {
            background-color: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #eaeaea;
        }

        .glass-card {
            background: #ffffff;
            border: 1px solid #eaeaea;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        .table {
            color: #444;
            vertical-align: middle;
        }
        .table thead th {
            border-bottom-width: 1px;
            font-weight: 600;
            color: #555;
            background-color: #f8f9fa;
        }
        
        .nav-underline .nav-link {
            color: #6c757d;
            font-weight: 600;
            padding: 12px 20px;
            border-bottom-width: 3px;
            transition: all 0.3s ease;
        }
        .nav-underline .nav-link.active {
            color: #0d6efd;
            border-bottom-color: #0d6efd;
        }
        .nav-underline .nav-link:hover:not(.active) {
            border-bottom-color: #dee2e6;
            color: #333;
        }
        
        .truncate-text {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pagination .page-link {
            color: #0d6efd;
            border: none;
            border-radius: 6px;
            margin: 0 2px;
            cursor: pointer;
        }
        .pagination .page-item.active .page-link {
            background-color: #0d6efd;
            color: white;
            box-shadow: 0 2px 5px rgba(13, 110, 253, 0.3);
        }
        .pagination .page-link:hover:not(.active) {
            background-color: #f1f3f5;
        }
    </style>
</head>
<body>
    <div id="app">
        <!-- Fixed Navbar -->
        <nav class="navbar navbar-expand-lg navbar-light navbar-custom fixed-top shadow-sm py-3">
            <div class="container">
                <a class="navbar-brand fw-bold text-primary d-flex align-items-center" href="#">
                    <img src="assets/images/doogleLogo.png" alt="Doogle" height="30" class="me-2" style="filter: drop-shadow(0px 2px 4px rgba(0,0,0,0.1));">
                    Admin
                </a>
                <div class="d-flex gap-2">
                    <a href="reset_db.php" class="btn btn-outline-danger rounded-pill px-4 fw-semibold" onclick="return confirm('Yakin ingin mereset Database? Semua data akan dihapus permanen!')">🗑️ Reset DB</a>
                    <a href="index.php" class="btn btn-outline-primary rounded-pill px-4 fw-semibold">🏠 Ke Beranda</a>
                </div>
            </div>
        </nav>

        <div class="container pb-5">
            <div class="glass-card p-4">
                <!-- Tabs -->
                <ul class="nav nav-underline mb-4 border-bottom">
                    <li class="nav-item">
                        <a class="nav-link" :class="{ active: activeTab === 'sites' }" @click="switchTab('sites')" href="#">🌐 Semua (Situs)</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" :class="{ active: activeTab === 'images' }" @click="switchTab('images')" href="#">🖼️ Gambar</a>
                    </li>
                </ul>

                <!-- Sites Section -->
                <div v-if="activeTab === 'sites'">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Indeks: {{ filteredSites.length }} item</h4>
                        <div class="d-flex align-items-center">
                            <input type="text" class="form-control me-3 rounded-pill px-3" v-model="searchJudul" placeholder="Cari Nama Barang..." style="width: 250px;">
                            <button class="btn btn-primary shadow-sm rounded-pill px-4" @click="openModal('sites')">+ Tambah Situs</button>
                        </div>
                    </div>
                    
                    <div class="table-responsive border rounded-3 mb-3">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Barang</th>
                                    <th>Klik</th>
                                    <th class="text-end pe-4">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="site in paginatedSites" :key="site.id">
                                    <td class="fw-semibold text-secondary">{{ site.id }}</td>
                                    <td class="truncate-text fw-medium">{{ site.title }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ site.clicks }}</span></td>
                                    <td class="text-end pe-3">
                                        <button class="btn btn-sm btn-light border me-2 text-primary fw-semibold" @click="openModal('sites', site)">Ubah</button>
                                        <button class="btn btn-sm btn-light border text-danger fw-semibold" @click="deleteData('site', site.id)">Hapus</button>
                                    </td>
                                </tr>
                                <tr v-if="paginatedSites.length === 0">
                                    <td colspan="5" class="text-center py-5 text-muted">Belum ada data situs.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Vue -->
                    <nav v-if="totalSitePages > 1" class="d-flex justify-content-center mt-4">
                        <ul class="pagination">
                            <li class="page-item" :class="{ disabled: currentSitePage === 1 }">
                                <a class="page-link" @click="currentSitePage--" href="#">&laquo; Prev</a>
                            </li>
                            <li class="page-item" v-for="page in totalSitePages" :key="page" :class="{ active: currentSitePage === page }">
                                <a class="page-link" @click="currentSitePage = page" href="#">{{ page }}</a>
                            </li>
                            <li class="page-item" :class="{ disabled: currentSitePage === totalSitePages }">
                                <a class="page-link" @click="currentSitePage++" href="#">Next &raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>

                <!-- Images Section -->
                <div v-if="activeTab === 'images'">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="fw-bold mb-0">Indeks: {{ filteredImages.length }} item</h4>
                        <div class="d-flex align-items-center">
                            <input type="text" class="form-control me-3 rounded-pill px-3" v-model="searchJudul" placeholder="Cari Nama Barang..." style="width: 250px;">
                        </div>
                    </div>
                    
                    <div class="table-responsive border rounded-3 mb-3">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Foto</th>
                                    <th>Nama Barang</th>
                                    <th>Klik</th>
                                    <th class="text-end pe-4">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="img in paginatedImages" :key="img.id">
                                    <td class="fw-semibold text-secondary">{{ img.id }}</td>
                                    <td>
                                        <div style="width: 72px; height: 45px; overflow: hidden; border: 1px solid #ddd;">
                                            <img :src="img.imageUrl" alt="img" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    </td>
                                    <td class="truncate-text fw-medium">{{ img.title || img.alt }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ img.clicks }}</span></td>
                                    <td class="text-end pe-3">
                                        <button class="btn btn-sm btn-light border me-2 text-primary fw-semibold" @click="openModal('images', img)">Ubah</button>
                                        <button class="btn btn-sm btn-light border text-danger fw-semibold" @click="deleteData('image', img.id)">Hapus</button>
                                    </td>
                                </tr>
                                <tr v-if="paginatedImages.length === 0">
                                    <td colspan="6" class="text-center py-5 text-muted">Belum ada data gambar.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Vue -->
                    <nav v-if="totalImagePages > 1" class="d-flex justify-content-center mt-4">
                        <ul class="pagination">
                            <li class="page-item" :class="{ disabled: currentImagePage === 1 }">
                                <a class="page-link" @click="currentImagePage--" href="#">&laquo; Prev</a>
                            </li>
                            <li class="page-item" v-for="page in totalImagePages" :key="page" :class="{ active: currentImagePage === page }">
                                <a class="page-link" @click="currentImagePage = page" href="#">{{ page }}</a>
                            </li>
                            <li class="page-item" :class="{ disabled: currentImagePage === totalImagePages }">
                                <a class="page-link" @click="currentImagePage++" href="#">Next &raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <!-- Modal Box -->
            <div class="modal fade" id="formModal" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-header bg-light">
                            <h5 class="modal-title fw-bold">{{ modalData.id ? 'Ubah' : 'Tambah' }} {{ modalType === 'sites' ? 'Situs' : 'Gambar' }}</h5>
                            <button type="button" class="btn-close" @click="closeModal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <!-- Form Sites -->
                            <div v-if="modalType === 'sites'">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">URL Situs</label>
                                    <input type="url" class="form-control" v-model="modalData.url" required placeholder="https://...">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nama Barang</label>
                                    <input type="text" class="form-control" v-model="modalData.title" placeholder="">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Deskripsi</label>
                                    <textarea class="form-control" rows="3" v-model="modalData.description"></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label fw-semibold">Kata Kunci (Keywords)</label>
                                        <input type="text" class="form-control" v-model="modalData.keywords" placeholder="meja, kayu, jati...">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label fw-semibold">Jumlah Klik</label>
                                        <input type="number" class="form-control" v-model="modalData.clicks">
                                    </div>
                                </div>
                            </div>

                            <!-- Form Images -->
                            <div v-if="modalType === 'images'">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">URL Situs</label>
                                            <input type="url" class="form-control" v-model="modalData.siteUrl" required>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Nama Barang</label>
                                                <input type="text" class="form-control" v-model="modalData.title">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Teks Alternatif (Alt)</label>
                                                <input type="text" class="form-control" v-model="modalData.alt">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Jumlah Klik</label>
                                                <input type="number" class="form-control" v-model="modalData.clicks">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label fw-semibold">Status Rusak (Broken)</label>
                                                <select class="form-select" v-model="modalData.broken">
                                                    <option value="0">0 (Gambar Normal)</option>
                                                    <option value="1">1 (Gambar Rusak)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4 d-flex flex-column align-items-center justify-content-center bg-light rounded border p-2">
                                        <div v-if="modalData.imageUrl" style="width:100%; height:200px; overflow:hidden; border-radius:8px;" class="mb-2">
                                            <img :src="modalData.imageUrl" alt="Preview" loading="lazy" style="width:100%; height:100%; object-fit:contain;">
                                        </div>
                                        <span v-else class="text-muted small mb-2">Preview Gambar</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light border-top-0">
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" @click="closeModal">Batal</button>
                            <button type="button" class="btn btn-primary rounded-pill px-4 shadow-sm" @click="saveData">Simpan Data</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Vue App Logic -->
    <script>
        const { createApp } = Vue;

        createApp({
            data() {
                return {
                    activeTab: 'sites',
                    searchJudul: '',
                    sites: [],
                    images: [],
                    filteredSites: [],
                    filteredImages: [],
                    
                    // Pagination Config
                    itemsPerPage: 10,
                    currentSitePage: 1,
                    currentImagePage: 1,
                    
                    modalType: '',
                    modalData: {},
                    bsModal: null
                };
            },
            watch: {
                searchJudul(newVal) {
                    const keyword = newVal.toLowerCase();
                    this.filteredSites = this.sites.filter(s => (s.title || '').toLowerCase().includes(keyword));
                    this.filteredImages = this.images.filter(i => (i.title || i.alt || '').toLowerCase().includes(keyword));
                    this.currentSitePage = 1;
                    this.currentImagePage = 1;
                }
            },
            computed: {
                // Computed logic untuk Sites
                totalSitePages() {
                    return Math.ceil(this.filteredSites.length / this.itemsPerPage);
                },
                paginatedSites() {
                    const start = (this.currentSitePage - 1) * this.itemsPerPage;
                    return this.filteredSites.slice(start, start + this.itemsPerPage);
                },
                // Computed logic untuk Images
                totalImagePages() {
                    return Math.ceil(this.filteredImages.length / this.itemsPerPage);
                },
                paginatedImages() {
                    const start = (this.currentImagePage - 1) * this.itemsPerPage;
                    return this.filteredImages.slice(start, start + this.itemsPerPage);
                }
            },
            mounted() {
                this.bsModal = new bootstrap.Modal(document.getElementById('formModal'));
                this.fetchSites();
                this.fetchImages();
            },
            methods: {
                switchTab(tab) {
                    this.activeTab = tab;
                    this.searchJudul = '';
                },
                async fetchSites() {
                    const res = await fetch('admin.php?action=get_sites');
                    const json = await res.json();
                    if(json.status === 'success') {
                        this.sites = json.data;
                        this.filteredSites = this.sites;
                    }
                },
                async fetchImages() {
                    const res = await fetch('admin.php?action=get_images');
                    const json = await res.json();
                    if(json.status === 'success') {
                        this.images = json.data;
                        this.filteredImages = this.images;
                    }
                },
                openModal(type, item = null) {
                    this.modalType = type;
                    if(item) {
                        this.modalData = { ...item };
                    } else {
                        // Default empty form
                        this.modalData = type === 'sites' 
                            ? { url:'', title:'', description:'', keywords:'', clicks:0 }
                            : { siteUrl:'', imageUrl:'', title:'', alt:'', clicks:0, broken:0 };
                    }
                    this.bsModal.show();
                },
                closeModal() {
                    this.bsModal.hide();
                    this.modalData = {};
                },
                async saveData() {
                    const action = this.modalType === 'sites' ? 'save_site' : 'save_image';
                    const res = await fetch(`admin.php?action=${action}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(this.modalData)
                    });
                    const json = await res.json();
                    if(json.status === 'success') {
                        this.modalType === 'sites' ? this.fetchSites() : this.fetchImages();
                        this.closeModal();
                    } else {
                        alert('Gagal menyimpan: ' + json.message);
                    }
                },
                async deleteData(type, id) {
                    const result = await Swal.fire({
                        title: `Hapus ${type === 'site' ? 'Situs' : 'Gambar'}?`,
                        text: "Data ini akan masuk ke dalam kotak sampah (Soft Delete) dan disembunyikan dari hasil pencarian.",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#dc3545',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Ya, Hapus!',
                        cancelButtonText: 'Batal'
                    });

                    if (!result.isConfirmed) return;
                    
                    const action = type === 'site' ? 'delete_site' : 'delete_image';
                    const res = await fetch(`admin.php?action=${action}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    const json = await res.json();
                    if(json.status === 'success') {
                        type === 'site' ? this.fetchSites() : this.fetchImages();
                        
                        // Cek jika data yang dihapus ada di halaman terakhir yang halamannya jadi kosong
                        if (type === 'site' && this.paginatedSites.length === 1 && this.currentSitePage > 1) {
                            this.currentSitePage--;
                        }
                        if (type === 'image' && this.paginatedImages.length === 1 && this.currentImagePage > 1) {
                            this.currentImagePage--;
                        }
                    } else {
                        alert('Gagal menghapus!');
                    }
                }
            }
        }).mount('#app');
    </script>
</body>
</html>
