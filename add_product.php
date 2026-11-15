<?php
session_start();
require_once '../config.php';

// التحقق من صلاحيات المشرف (تم التأكد من وجود الجلسة)
if (!isset($_SESSION['admin']) || $_SESSION['admin'] != 1) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$message_type = '';

// معالجة إضافة المنتج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $old_price = floatval($_POST['old_price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_new = isset($_POST['is_new']) ? 1 : 0;
    
    // رفع الصورة (تم تحسين التحقق من الأمان)
    $image_name = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        // التحقق من أن الملف هو صورة فعلاً وليس مجرد امتداد
        $check_image = getimagesize($_FILES['image']['tmp_name']);
        if ($check_image !== false && in_array($file_ext, $allowed_exts)) {
            $image_name = 'product_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $target_path = $upload_dir . $image_name;
            
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                $message = '❌ فشل في رفع الصورة';
                $message_type = 'error';
            }
        } else {
            $message = '❌ الملف المرفوع ليس صورة صالحة أو الصيغة غير مدعومة.';
            $message_type = 'error';
        }
    }
    
    // إدخال المنتج في قاعدة البيانات
    if (empty($message) && !empty($name) && !empty($category) && $price > 0) {
        $stmt = $conn->prepare("INSERT INTO products (name, category, price, old_price, description, image, is_new, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssddssi", $name, $category, $price, $old_price, $description, $image_name, $is_new);
        
        if ($stmt->execute()) {
            $message = '✅ تمت إضافة المنتج بنجاح!';
            $message_type = 'success';
            $_POST = []; // إعادة تعيين الحقول
        } else {
            // إخفاء تفاصيل الخطأ عن المستخدم وتسجيلها في اللوج فقط
            error_log('Add product error: ' . $stmt->error);
            $message = '❌ حدث خطأ أثناء إضافة المنتج، يرجى المحاولة لاحقاً.';
            $message_type = 'error';
        }
        $stmt->close();
    } elseif (empty($message)) {
        $message = '❌ يرجى ملء جميع الحقول المطلوبة';
        $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة منتج | لوحة التحكم</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f8fafc;
            --white: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
        }

        .admin-header {
            background: #1e293b;
            color: white;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .admin-header h2 {
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-header a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 7px 14px;
            border-radius: 8px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .admin-header a:hover { background: rgba(255,255,255,0.1); }
        .admin-header .btn-back { background: #334155; }
        .admin-header .btn-back:hover { background: #475569; }

        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .form-card {
            background: var(--white);
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }

        .form-card h1 {
            font-size: 1.5rem;
            font-weight: 900;
            margin-bottom: 25px;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert {
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 6px;
            font-size: 0.9rem;
            color: var(--text);
        }

        .form-group label .required {
            color: var(--danger);
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-family: 'Tajawal', sans-serif;
            font-size: 0.9rem;
            transition: all 0.2s;
            background: var(--bg);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
            background: var(--white);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group input[type="file"] {
            padding: 10px;
            border: 2px dashed var(--border);
            border-radius: 10px;
            width: 100%;
            cursor: pointer;
            background: var(--bg);
            font-family: 'Tajawal', sans-serif;
        }

        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .form-row .form-group { flex: 1; min-width: 200px; }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 15px 0;
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .checkbox-group label {
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Tajawal', sans-serif;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37,99,235,0.3);
        }

        .image-preview {
            margin-top: 10px;
            text-align: center;
        }
        .image-preview img {
            max-width: 250px;
            max-height: 200px;
            border-radius: 10px;
            border: 2px solid var(--border);
            object-fit: cover;
        }

        @media (max-width: 768px) {
            .form-card { padding: 20px 15px; }
            .form-row { flex-direction: column; gap: 0; }
            .admin-header { padding: 12px 15px; }
            .admin-header h2 { font-size: 1rem; }
        }
    </style>
</head>
<body>

<div class="admin-header">
    <h2><i class="fas fa-crown"></i> لوحة التحكم</h2>
    <div>
        <a href="index.php" class="btn-back"><i class="fas fa-tachometer-alt"></i> الرئيسية</a>
        <a href="../index.php"><i class="fas fa-store"></i> المتجر</a>
        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> خروج</a>
    </div>
</div>

<div class="container">
    <div class="form-card">
        <h1><i class="fas fa-plus-circle"></i> إضافة منتج جديد</h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>اسم المنتج <span class="required">*</span></label>
                <input type="text" name="name" placeholder="أدخل اسم المنتج" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>التصنيف <span class="required">*</span></label>
                    <select name="category" required>
                        <option value="">-- اختر التصنيف --</option>
                        <option value="إلكترونيات" <?php echo (isset($_POST['category']) && $_POST['category'] == 'إلكترونيات') ? 'selected' : ''; ?>>💻 إلكترونيات</option>
                        <option value="هواتف ذكية وإكسسوارات" <?php echo (isset($_POST['category']) && $_POST['category'] == 'هواتف ذكية وإكسسوارات') ? 'selected' : ''; ?>>📱 هواتف ذكية وإكسسوارات</option>
                        <option value="كمبيوتر ولابتوب" <?php echo (isset($_POST['category']) && $_POST['category'] == 'كمبيوتر ولابتوب') ? 'selected' : ''; ?>>🖥️ كمبيوتر ولابتوب</option>
                        <option value="ألعاب فيديو" <?php echo (isset($_POST['category']) && $_POST['category'] == 'ألعاب فيديو') ? 'selected' : ''; ?>>🎮 ألعاب فيديو</option>
                        <option value="ألعاب" <?php echo (isset($_POST['category']) && $_POST['category'] == 'ألعاب') ? 'selected' : ''; ?>>🧸 ألعاب</option>
                        <option value="أطفال ورضع" <?php echo (isset($_POST['category']) && $_POST['category'] == 'أطفال ورضع') ? 'selected' : ''; ?>>🍼 أطفال ورضع</option>
                        <option value="صيدلية وصحة وعافية" <?php echo (isset($_POST['category']) && $_POST['category'] == 'صيدلية وصحة وعافية') ? 'selected' : ''; ?>>⚕️ صيدلية وصحة وعافية</option>
                        <option value="عناية شخصية" <?php echo (isset($_POST['category']) && $_POST['category'] == 'عناية شخصية') ? 'selected' : ''; ?>>🧼 عناية شخصية</option>
                        <option value="تجميل" <?php echo (isset($_POST['category']) && $_POST['category'] == 'تجميل') ? 'selected' : ''; ?>>💄 تجميل</option>
                        <option value="عطور" <?php echo (isset($_POST['category']) && $_POST['category'] == 'عطور') ? 'selected' : ''; ?>>🌸 عطور</option>
                        <option value="سيارات وإطارات" <?php echo (isset($_POST['category']) && $_POST['category'] == 'سيارات وإطارات') ? 'selected' : ''; ?>>🚗 سيارات وإطارات</option>
                        <option value="مستلزمات منزلية" <?php echo (isset($_POST['category']) && $_POST['category'] == 'مستلزمات منزلية') ? 'selected' : ''; ?>>🧻 مستلزمات منزلية</option>
                        <option value="منزل، أثاث وأجهزة" <?php echo (isset($_POST['category']) && $_POST['category'] == 'منزل، أثاث وأجهزة') ? 'selected' : ''; ?>>🛋️ منزل، أثاث وأجهزة</option>
                        <option value="أدوات المطبخ" <?php echo (isset($_POST['category']) && $_POST['category'] == 'أدوات المطبخ') ? 'selected' : ''; ?>>🍳 أدوات المطبخ</option>
                        <option value="رياضة وخارج المنزل" <?php echo (isset($_POST['category']) && $_POST['category'] == 'رياضة وخارج المنزل') ? 'selected' : ''; ?>>⚽ رياضة وخارج المنزل</option>
                        <option value="ملابس، أحذية وإكسسوارات" <?php echo (isset($_POST['category']) && $_POST['category'] == 'ملابس، أحذية وإكسسوارات') ? 'selected' : ''; ?>>👕 ملابس، أحذية وإكسسوارات</option>
                        <option value="ساعات ومجوهرات" <?php echo (isset($_POST['category']) && $_POST['category'] == 'ساعات ومجوهرات') ? 'selected' : ''; ?>>⌚ ساعات ومجوهرات</option>
                        <option value="كتب" <?php echo (isset($_POST['category']) && $_POST['category'] == 'كتب') ? 'selected' : ''; ?>>📖 كتب</option>
                        <option value="بقالة" <?php echo (isset($_POST['category']) && $_POST['category'] == 'بقالة') ? 'selected' : ''; ?>>🛒 بقالة</option>
                        <option value="بطاقات هدايا" <?php echo (isset($_POST['category']) && $_POST['category'] == 'بطاقات هدايا') ? 'selected' : ''; ?>>🎁 بطاقات هدايا</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>السعر <span class="required">*</span> (ريال)</label>
                    <input type="number" name="price" placeholder="مثال: 99.99" step="0.01" min="0.01" value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>السعر القديم (للتخفيض)</label>
                    <input type="number" name="old_price" placeholder="اتركه فارغاً إذا لم يكن هناك تخفيض" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['old_price'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>الوصف</label>
                <textarea name="description" placeholder="أدخل وصف المنتج..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>صورة المنتج</label>
                <input type="file" name="image" accept="image/*" onchange="previewImage(event)">
                <div class="image-preview" id="imagePreview"></div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="is_new" id="is_new" <?php echo isset($_POST['is_new']) ? 'checked' : ''; ?>>
                <label for="is_new">🆕 تحديد كمنتج جديد</label>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-plus-circle"></i> إضافة المنتج
            </button>
        </form>
    </div>
</div>

<script>
    function previewImage(event) {
        const preview = document.getElementById('imagePreview');
        const file = event.target.files[0];
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = '<img src="' + e.target.result + '" alt="معاينة الصورة">';
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = '';
        }
    }
</script>

</body>
</html>
