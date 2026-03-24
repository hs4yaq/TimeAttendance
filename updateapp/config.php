<?php
// ==========================================
// ระบบอัพเดทไฟล์ (ไฟล์ตั้งค่า)
// ==========================================

if (!defined('SECURE_ACCESS')) {
    header("HTTP/1.1 403 Forbidden");
    die('❌ ไม่อนุญาตให้เข้าถึงไฟล์นี้โดยตรง');
}

// 1. นำ Client ID จาก Google Cloud Console มาใส่ที่นี่
define('GOOGLE_CLIENT_ID', '=======================แก้ไขตรงนี้=======================');

// 2. กำหนดอีเมล (Gmail) ที่อนุญาตให้ล็อคอินเข้าระบบได้ (ใส่กี่อีเมลก็ได้)
define('ALLOWED_EMAILS', [
    'hs4yaq@gmail.com',
    'suppachai.hom@ptc.ac.th'
]);

// 3. ชื่อโฟลเดอร์สำหรับเก็บไฟล์ Backup แยกตามสภาพแวดล้อม
define('BACKUP_DIR_DEV', 'backup/dev');
define('BACKUP_DIR_PROD', 'backup/prod');

// 4. ไฟล์สำหรับทดสอบ (Development) - ระบบจะแก้ไขไฟล์นี้เป็นหลัก
define('DEV_FILE', '../dev.html');

// 5. ไฟล์หน้าเว็บจริง (Production)
define('PROD_FILE', '../index.html');
?>