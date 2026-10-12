<?php
// ========================================================
// ERROR HANDLING & SESSION SETUP
// ========================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);

session_start();

// Auth: hanya admin
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

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$admin_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

$scooter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Ambil data scooter
$scooter = null;
if ($scooter_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM scooters WHERE scooter_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $scooter_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $scooter = $result->fetch_assoc();
        $stmt->close();
    }
}

if (!$scooter) {
    $_SESSION['error'] = "Scooter not found!";
    header("Location: admin_manage.php");
    exit;
}

$errors = [];

// Proses update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ========== CSRF VALIDATION (timing-safe) ==========
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Security validation failed. Please refresh the page and try again.";
    }
    
    if (empty($errors)) {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $brand = isset($_POST['brand']) ? trim($_POST['brand']) : '';
        $type = isset($_POST['type']) ? strtolower(trim($_POST['type'])) : '';
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        $status = isset($_POST['status']) ? strtolower(trim($_POST['status'])) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        // Batasi panjang
        $name = mb_substr($name, 0, 150);
        $brand = mb_substr($brand, 0, 100);
        $description = mb_substr($description, 0, 5000);
        $current_image = $scooter['image'] ?? null;
        $imageName = $current_image;
        
        // Validasi input + whitelist (harus cocok dengan ENUM DB)
        if (empty($name)) $errors[] = "Name is required";
        if (empty($brand)) $errors[] = "Brand is required";
        if (!in_array($type, ['electric', 'gasoline'], true)) $errors[] = "Invalid type. Choose Electric or Gasoline.";
        if ($price <= 0) $errors[] = "Price must be greater than 0";
        if (!in_array($status, ['available', 'rented', 'maintenance'], true)) $errors[] = "Invalid status.";
        
        // Image upload handling (validasi MIME + anti double-extension)
        $newImageUploaded = false;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];

            if (!in_array($ext, $allowed, true)) {
                $errors[] = "Invalid image type. Allowed: JPG, PNG, WEBP";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $errors[] = "Image size too large. Maximum 5MB allowed";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeReal = $finfo->file($_FILES['image']['tmp_name']);
                if (!in_array($mimeReal, $allowed_mime, true) || @getimagesize($_FILES['image']['tmp_name']) === false) {
                    $errors[] = "Invalid image content. Upload a real JPG/PNG/WEBP file.";
                } else {
                    if (!is_dir(__DIR__ . '/../uploads')) mkdir(__DIR__ . '/../uploads', 0755, true);
                    $safeName = preg_replace('/[^a-z0-9._-]/i', '_', basename($_FILES['image']['name']));
                    $baseName = pathinfo($safeName, PATHINFO_FILENAME);
                    $imageName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $baseName . '.' . $ext;
                    $uploadPath = __DIR__ . '/../uploads/' . $imageName;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
                    $newImageUploaded = true;
                } else {
                    $errors[] = "Failed to upload image";
                    $imageName = $current_image;
                }
                }
            }
        }

        if (empty($errors)) {
            // Gunakan transaction untuk keamanan
            $conn->begin_transaction();
            
            try {
                $update_stmt = $conn->prepare("
                    UPDATE scooters 
                    SET name=?, brand=?, type=?, price=?, status=?, description=?, image=?
                    WHERE scooter_id=?
                ");
                
                $update_stmt->bind_param("sssdsssi", $name, $brand, $type, $price, $status, $description, $imageName, $scooter_id);
                
                if ($update_stmt->execute()) {
                    // Hapus gambar lama hanya jika upload gambar baru BERHASIL
                    if ($newImageUploaded && $current_image && file_exists(__DIR__ . '/../uploads/' . $current_image)) {
                        unlink(__DIR__ . '/../uploads/' . $current_image);
                    }
                    
                    $conn->commit();
                    $_SESSION['success'] = "Scooter updated successfully!";
                    header("Location: admin_manage.php");
                    exit;
                } else {
                    throw new Exception("Failed to update scooter: " . $update_stmt->error);
                }
                $update_stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                // Hapus gambar baru jika upload gagal
                if ($newImageUploaded && file_exists(__DIR__ . '/../uploads/' . $imageName)) {
                    unlink(__DIR__ . '/../uploads/' . $imageName);
                }
                $errors[] = $e->getMessage();
                error_log("Edit Scooter Error: " . $e->getMessage());
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
    <title>Edit Scooter â€” Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../css/adminedit.css?v=20261007d">
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
        <a href="admin_add.php" class="sb-link">
            <i class="fas fa-plus-circle"></i> Add Scooter
        </a>
        <a href="admin_manage.php" class="sb-link active">
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
            <h1>EDIT <span>SCOOTER</span></h1>
            <p class="page-subtitle">Update scooter #<?= $scooter['scooter_id'] ?> â€” <?= htmlspecialchars($scooter['name']) ?></p>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h2><i class="fas fa-edit"></i> Edit Scooter Details</h2>
                <p>Update the information below to modify this scooter</p>
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

                <?php if(!empty($scooter['image'])): ?>
                <div class="current-image-section">
                    <h3><i class="fas fa-image"></i> Current Image</h3>
                    <p>This is the current image of the scooter. Upload a new one to replace it.</p>
                    <div class="image-container">
                        <img src="../uploads/<?= htmlspecialchars($scooter['image']) ?>" 
                             alt="Current scooter image" 
                             onerror="this.src='../uploads/default_scooter.jpg'">
                    </div>
                </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" id="editScooterForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tag"></i> Scooter Name <span class="required">*</span>
                            </label>
                            <input type="text" name="name" class="form-control" 
                                   placeholder="e.g., Premium Electric Scooter" required
                                   value="<?= htmlspecialchars($scooter['name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-building"></i> Brand <span class="required">*</span>
                            </label>
                            <input type="text" name="brand" class="form-control" 
                                   placeholder="e.g., Xiaomi, Segway, Niu" required
                                   value="<?= htmlspecialchars($scooter['brand'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-bolt"></i> Scooter Type <span class="required">*</span>
                            </label>
                            <select name="type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="electric" <?= ($scooter['type'] ?? '') == 'electric' ? 'selected' : '' ?>>Electric</option>
                                <option value="gasoline" <?= ($scooter['type'] ?? '') == 'gasoline' ? 'selected' : '' ?>>Gasoline</option>
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
                                       value="<?= htmlspecialchars($scooter['price'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-info-circle"></i> Status <span class="required">*</span>
                            </label>
                            <select name="status" class="form-control" required>
                                <option value="">Select Status</option>
                                <option value="available" <?= ($scooter['status'] ?? '') == 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="rented" <?= ($scooter['status'] ?? '') == 'rented' ? 'selected' : '' ?>>Rented</option>
                                <option value="maintenance" <?= ($scooter['status'] ?? '') == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i> Description
                            </label>
                            <textarea name="description" class="form-control" 
                                      placeholder="Describe the scooter's features, condition, and specifications..."><?= htmlspecialchars($scooter['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-image"></i> Update Scooter Image (Optional)
                            </label>
                            <div class="file-upload">
                                <input type="file" id="image" name="image" accept="image/*">
                                <div class="upload-area" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="upload-text">
                                        <h4>Click to upload new image</h4>
                                        <p>JPEG, PNG, or WEBP (Max 5MB)</p>
                                    </div>
                                </div>
                                <div class="file-preview" id="filePreview"></div>
                            </div>
                        </div>

                        <div class="form-actions full-width">
                            <!-- Tombol Cancel hanya tampil di desktop -->
                            <a href="admin_manage.php" class="btn btn-secondary btn-cancel-desktop">
                                <i class="fas fa-arrow-left"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-submit">
                                <i class="fas fa-save"></i>
                                <span>Update Scooter</span>
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
            alert('File size exceeds 5MB limit');
            fileInput.value = '';
            filePreview.innerHTML = '';
            uploadArea.querySelector('.upload-text h4').textContent = 'Click to upload new image';
            uploadArea.querySelector('.upload-text p').textContent = 'JPEG, PNG, or WEBP (Max 5MB)';
            return;
        }
        
        const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            alert('Invalid file type. Please upload an image (JPEG, PNG, WEBP)');
            fileInput.value = '';
            filePreview.innerHTML = '';
            uploadArea.querySelector('.upload-text h4').textContent = 'Click to upload new image';
            uploadArea.querySelector('.upload-text p').textContent = 'JPEG, PNG, or WEBP (Max 5MB)';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            filePreview.innerHTML = `
                <img src="${e.target.result}" class="preview-image" alt="Preview">
                <div class="file-name">${file.name} (New image will replace current)</div>
            `;
        };
        reader.readAsDataURL(file);
        
        uploadArea.querySelector('.upload-text h4').textContent = 'New file selected';
        uploadArea.querySelector('.upload-text p').textContent = 'Click to change file';
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