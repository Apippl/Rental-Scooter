<?php
// Error handling â€” harus paling atas
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

// Session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../user/index.php");
    exit;
}

$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: ../user/index.php?timeout=1");
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

require "../config/db.php";

$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF VALIDATION (timing-safe)
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Security validation failed. Please refresh the page and try again.";
    }
    
    if (empty($errors)) {
        $name = trim($_POST['name'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $type = strtolower(trim($_POST['type'] ?? ''));
        $price = floatval($_POST['price'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        // Batasi panjang agar tidak overflow kolom DB
        $name = mb_substr($name, 0, 150);
        $brand = mb_substr($brand, 0, 100);
        $desc = mb_substr($desc, 0, 5000);
        $imageName = null;

        if (empty($name)) $errors[] = "Name is required";
        if ($price <= 0) $errors[] = "Price must be greater than 0";
        // Whitelist type — cegah nilai invalid masuk ENUM/string liar
        if (!in_array($type, ['electric', 'gasoline'], true)) $errors[] = "Invalid scooter type.";
        
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            // gif dihapus dari whitelist (rawan polyglot XSS) â€” samakan dengan validasi JS
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];

            if (!in_array($ext, $allowed, true)) {
                $errors[] = "Invalid image type. Allowed: JPG, PNG, WEBP";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $errors[] = "Image size too large. Maximum 5MB allowed";
            } else {
                // Validasi MIME dari konten file (bukan header browser)
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeReal = $finfo->file($_FILES['image']['tmp_name']);
                if (!in_array($mimeReal, $allowed_mime, true)) {
                    $errors[] = "Invalid image content. Upload a real JPG/PNG/WEBP file.";
                } elseif (@getimagesize($_FILES['image']['tmp_name']) === false) {
                    $errors[] = "Invalid image content. Upload a real JPG/PNG/WEBP file.";
                } else {
                    if (!is_dir(__DIR__ . '/../uploads')) mkdir(__DIR__ . '/../uploads', 0755, true);
                    $safeName = preg_replace('/[^a-z0-9._-]/i', '_', basename($_FILES['image']['name']));
                    // Buang ekstensi ganda berbahaya: ambil basename tanpa ext lalu tambah ext valid
                    $baseName = pathinfo($safeName, PATHINFO_FILENAME);
                    $imageName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $baseName . '.' . $ext;
                    $uploadPath = __DIR__ . '/../uploads/' . $imageName;

                    if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
                        $errors[] = "Failed to upload image";
                    }
                }
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO scooters (name, brand, type, price, status, description, image) VALUES (?, ?, ?, ?, 'available', ?, ?)");
            $stmt->bind_param("ssdsss", $name, $brand, $type, $price, $desc, $imageName);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Scooter added successfully!";
                header("Location: admin.php");
                exit;
            } else {
                $errors[] = "Database error: " . $conn->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Add Scooter â€” Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../css/adminadd.css?v=20261007d">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>

<header class="admin-header">
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>
    <a href="admin.php" class="header-brand">
        <div class="brand-wordmark">
            <strong>SCOOTER</strong>
            <small>ADMIN</small>
        </div>
    </a>
    <div class="header-spacer"></div>
    <div class="admin-pill">
        <div class="admin-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
        <div class="admin-pill-info">
            <strong><?= $admin_name ?></strong>
            <small>Administrator</small>
        </div>
    </div>
</header>

<div class="page-layout">

    <aside class="sidebar" id="sidebar">
        <div class="sb-label">Main</div>
        <a href="admin.php" class="sb-link">
            <i class="fas fa-th-large"></i> Dashboard
        </a>

        <div class="sb-label">Management</div>
        <a href="admin_add.php" class="sb-link active">
            <i class="fas fa-plus-circle"></i> Add Scooter
        </a>
        <a href="admin_manage.php" class="sb-link">
            <i class="fas fa-screwdriver-wrench"></i> Manage Scooters
        </a>
        <a href="admin_users.php" class="sb-link">
            <i class="fas fa-users"></i> Manage Users
        </a>

        <div class="sb-label">Reports</div>
        <a href="admin_history.php" class="sb-link">
            <i class="fas fa-history"></i> Rental History
        </a>

        <div class="sb-label">Site</div>
        <a href="../user/dashboard.php" class="sb-link" target="_blank">
            <i class="fas fa-external-link-alt"></i> View Site
        </a>
        <a href="../user/logout.php" class="sb-link danger" onclick="return swalConfirm(event,'Logout?','Kamu akan keluar dari Admin Panel.','warning','Ya, Logout',this.href)">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </aside>

    <div class="menu-overlay" id="menuOverlay"></div>

    <main class="main-content">
        <div class="page-title">
            <h1>ADD <span>SCOOTER</span></h1>
            <p class="page-subtitle">Add a new scooter to the rental fleet</p>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h2><i class="fas fa-plus-circle"></i> Scooter Details</h2>
                <p>Fill in the information below to add a new scooter</p>
            </div>
            
            <div class="form-body">
                <?php if(isset($_SESSION['success'])): ?>
                <div class="notification success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
                </div>
                <?php endif; ?>

                <?php if(!empty($errors)): ?>
                <div class="error-container">
                    <h4><i class="fas fa-exclamation-triangle"></i> Please fix the following errors:</h4>
                    <ul>
                        <?php foreach($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="status-display">
                    <i class="fas fa-check-circle"></i>
                    <h3>Status: Available</h3>
                    <p>All new scooters are automatically available for rental</p>
                </div>

                <form method="post" enctype="multipart/form-data" id="addScooterForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tag"></i> Scooter Name <span class="required">*</span>
                            </label>
                            <input type="text" name="name" class="form-control" 
                                   placeholder="e.g., Premium Electric Scooter" required
                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-building"></i> Brand
                            </label>
                            <input type="text" name="brand" class="form-control" 
                                   placeholder="e.g., Xiaomi, Segway, Niu"
                                   value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-bolt"></i> Scooter Type <span class="required">*</span>
                            </label>
                            <select name="type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="electric" <?= ($_POST['type'] ?? '') == 'electric' ? 'selected' : '' ?>>Electric</option>
                                <option value="gasoline" <?= ($_POST['type'] ?? '') == 'gasoline' ? 'selected' : '' ?>>Gasoline</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-money-bill-wave"></i> Daily Price (Rp) <span class="required">*</span>
                            </label>
                            <div class="price-input">
                                <span class="prefix">Rp</span>
                                <input type="number" name="price" class="form-control" 
                                       step="1000" min="0" required
                                       placeholder="50000"
                                       value="<?= htmlspecialchars($_POST['price'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i> Description
                            </label>
                            <textarea name="description" class="form-control" 
                                      placeholder="Describe the scooter's features, condition, and specifications..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-image"></i> Scooter Image
                            </label>
                            <div class="file-upload">
                                <input type="file" id="image" name="image" accept="image/*">
                                <div class="upload-area" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="upload-text">
                                        <h4>Click to upload image</h4>
                                        <p>JPEG, PNG, or WEBP (Max 5MB)</p>
                                    </div>
                                </div>
                                <div class="file-preview" id="filePreview"></div>
                            </div>
                        </div>

                        <div class="form-actions full-width">
                            <!-- Tombol Cancel hanya tampil di desktop -->
                            <a href="admin.php" class="btn btn-secondary btn-cancel-desktop">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-submit">
                                <i class="fas fa-save"></i>
                                <span>Add Scooter</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
// Mobile menu toggle
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('menuOverlay');

function closeMenu() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

function openMenu() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

menuToggle?.addEventListener('click', function(e) {
    e.stopPropagation();
    sidebar.classList.contains('open') ? closeMenu() : openMenu();
});

overlay?.addEventListener('click', closeMenu);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMenu(); });

// File upload preview
const fileInput = document.getElementById('image');
const uploadArea = document.getElementById('uploadArea');
const filePreview = document.getElementById('filePreview');

function handleFileSelect() {
    const file = fileInput.files[0];
    
    if (file) {
        if (file.size > 5 * 1024 * 1024) {
            Swal.fire({
                title: 'File Terlalu Besar',
                text: 'Ukuran file maksimal 5MB.',
                icon: 'error',
                confirmButtonColor: '#e53935',
                customClass: { popup: 'swal-admin-popup' }
            });
            fileInput.value = '';
            filePreview.innerHTML = '';
            uploadArea.querySelector('.upload-text h4').textContent = 'Click to upload image';
            uploadArea.querySelector('.upload-text p').textContent = 'JPEG, PNG, or WEBP (Max 5MB)';
            return;
        }
        
        const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            Swal.fire({
                title: 'Format Tidak Valid',
                text: 'Upload gambar dengan format JPEG, PNG, atau WEBP.',
                icon: 'error',
                confirmButtonColor: '#e53935',
                customClass: { popup: 'swal-admin-popup' }
            });
            fileInput.value = '';
            filePreview.innerHTML = '';
            uploadArea.querySelector('.upload-text h4').textContent = 'Click to upload image';
            uploadArea.querySelector('.upload-text p').textContent = 'JPEG, PNG, or WEBP (Max 5MB)';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            filePreview.innerHTML = `
                <img src="${e.target.result}" class="preview-image" alt="Preview">
                <div class="file-name">${file.name}</div>
            `;
        };
        reader.readAsDataURL(file);
        
        uploadArea.querySelector('.upload-text h4').textContent = 'File selected';
        uploadArea.querySelector('.upload-text p').textContent = 'Click to change';
    }
}

fileInput?.addEventListener('change', handleFileSelect);
uploadArea?.addEventListener('click', () => fileInput.click());

// Auto fade success message
setTimeout(() => {
    const successMsg = document.querySelector('.notification.success');
    if (successMsg) {
        successMsg.style.transition = 'opacity 0.5s';
        successMsg.style.opacity = '0';
        setTimeout(() => successMsg.remove(), 500);
    }
}, 4000);
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function swalConfirm(e, title, text, icon, confirmText, href) {
    e.preventDefault();
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Batal',
        confirmButtonColor: icon === 'warning' ? '#e53935'
                          : icon === 'success' ? '#2e7d32'
                          : '#1565c0',
        cancelButtonColor: '#78909c',
        reverseButtons: true,
        focusCancel: true,
        customClass: { popup: 'swal-admin-popup' }
    }).then(r => { if (r.isConfirmed) window.location.href = href; });
    return false;
}
</script>
</body>
</html>