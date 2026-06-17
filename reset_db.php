<?php
include("config.php");

$isStream = isset($_GET['stream']);
if ($isStream) {
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache');
    function out($msg) {
        echo $msg . "\n";
        @ob_flush();
        @flush();
    }
    out("[INFO] Memulai proses reset database...");
    sleep(1); // Memberikan jeda singkat agar terkesan sebagai sebuah proses di terminal
}

try {
    if ($isStream) out("[INFO] Menghapus semua isi tabel cari_sites...");
    $con->exec("TRUNCATE TABLE cari_sites");
    if ($isStream) out("[INFO] Menghapus semua isi tabel cari_images...");
    $con->exec("TRUNCATE TABLE cari_images");

    if ($isStream) out("[INFO] Mereset urutan Auto Increment ID...");
    $con->exec("ALTER TABLE cari_sites AUTO_INCREMENT = 1");
    $con->exec("ALTER TABLE cari_images AUTO_INCREMENT = 1");

    if ($isStream) {
        out("[SUCCESS] Database selesai dibersihkan secara total!");
        out("[SUCCESS] Auto Increment dikembalikan ke 0.");
    } else if (php_sapi_name() !== 'cli') {
        echo "<script>alert('Database berhasil direset!'); window.location.href='admin.php';</script>";
    } else {
        echo "\n[SUKSES] Database selesai dibersihkan secara total!\n";
        echo "[SUKSES] Auto Increment dikembalikan ke 0.\n\n";
    }
} catch(PDOException $e) {
    if ($isStream) {
        out("[ERROR] Terjadi kesalahan: " . $e->getMessage());
    } else if (php_sapi_name() !== 'cli') {
        echo "Gagal: " . $e->getMessage();
    } else {
        echo "\n[GAGAL] " . $e->getMessage() . "\n\n";
    }
}
?>
