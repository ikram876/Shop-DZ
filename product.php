<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // إخفاء الأخطاء في الإنتاج
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
session_start();

require_once 'config.php';

// ========== جلب معرف المنتج ==========
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id <= 0) {
    header('Location: index.php');
    exit;
}

// ========== معالجة طلبات POST (السلة، المفضلة، التقييم) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => ''];
    $action = $_POST['action'];

    // 1. إضافة إلى السلة
    if ($action === 'add_to_cart' && isset($_POST['product_id'])) {
        $pid = intval($_POST['product_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        
        // توحيد بنية السلة لتتوافق مع cart.php
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        if (isset($_SESSION['cart'][$pid])) {
            $_SESSION['cart'][$pid]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$pid] = ['quantity' => $quantity];
        }
        $response['success'] = true;
        $response['message'] = 'تمت إضافة المنتج إلى السلة';
    }

    // 2. إضافة/إزالة من المفضلة
    if ($action === 'toggle_wishlist' && isset($_POST['product_id'])) {
        $pid = intval($_POST['product_id']);
        if (!isset($_SESSION['wishlist'])) $_SESSION['wishlist'] = [];
        $index = array_search($pid, $_SESSION['wishlist']);
        if ($index !== false) {
            unset($_SESSION['wishlist'][$index]);
            $_SESSION['wishlist'] = array_values($_SESSION['wishlist']);
            $response['message'] = 'تمت الإزالة من المفضلة';
            $response['action'] = 'removed';
        } else {
            $_SESSION['wishlist'][] = $pid;
            $response['message'] = 'تمت الإضافة إلى المفضلة';
            $response['action'] = 'added';
        }
        $response['success'] = true;
    }

    // 3. إضافة تقييم
    if ($action === 'submit_review') {
        if (!isset($_SESSION['user_id'])) {
            $response['message'] = 'يجب تسجيل الدخول لإضافة تقييم';
        } else {
            $rating = intval($_POST['rating'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            if ($rating < 1 || $rating > 5) {
                $response['message'] = 'يرجى اختيار تقييم من 1 إلى 5';
            } elseif (empty($comment)) {
                $response['message'] = 'يرجى كتابة تعليق';
            } else {
                // التحقق من عدم التقييم المسبق
                $check = $conn->prepare("SELECT id FROM reviews WHERE product_id = ? AND user_id = ?");
                $check->bind_param("ii", $product_id, $_SESSION['user_id']);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    $response['message'] = 'لقد قمت بتقييم هذا المنتج مسبقاً';
                } else {
                    $stmt = $conn->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iiis", $product_id, $_SESSION['user_id'], $rating, $comment);
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'تمت إضافة تقييمك بنجاح';
                    }
                }
            }
        }
    }

    echo json_encode($response);
    exit;
}

// ========== جلب معلومات المنتج ==========
$product = null;
$specifications = [];
$product_features = [];
$similar_products = [];
$gallery_images = [];

try {
    // جلب المنتج
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    
    if (!$product) {
        header('Location: index.php');
        exit;
    }
    
    // جلب صور المعرض
    $stmt_gallery = $conn->prepare("SELECT image FROM product_images WHERE product_id = ?");
    $stmt_gallery->bind_param("i", $product_id);
    $stmt_gallery->execute();
    $result_gallery = $stmt_gallery->get_result();
    while ($row = $result_gallery->fetch_assoc()) {
        $gallery_images[] = $row['image'];
    }
    if (empty($gallery_images) && !empty($product['image'])) {
        $gallery_images[] = $product['image'];
    }
    
    // جلب المواصفات
    $stmt_specs = $conn->prepare("SELECT * FROM specifications WHERE product_id = ?");
    $stmt_specs->bind_param("i", $product_id);
    $stmt_specs->execute();
    $result_specs = $stmt_specs->get_result();
    while ($row = $result_specs->fetch_assoc()) {
        $specifications[] = $row;
    }
    
    // جلب الميزات
    $stmt_features = $conn->prepare("SELECT * FROM product_features WHERE product_id = ?");
    $stmt_features->bind_param("i", $product_id);
    $stmt_features->execute();
    $result_features = $stmt_features->get_result();
    while ($row = $result_features->fetch_assoc()) {
        $product_features[] = $row;
    }
    
    // جلب منتجات مشابهة
    if (!empty($product['category'])) {
        $stmt_similar = $conn->prepare("SELECT * FROM products WHERE category = ? AND id != ? LIMIT 3");
        $stmt_similar->bind_param("si", $product['category'], $product_id);
        $stmt_similar->execute();
        $result_similar = $stmt_similar->get_result();
        while ($row = $result_similar->fetch_assoc()) {
            $similar_products[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error in product.php: " . $e->getMessage());
}

// ========== نظام التقييمات ==========
$avg_rating = 0;
$total_reviews = 0;
$reviews_list = [];
$user_has_reviewed = false;

try {
    // حساب المتوسط والعدد
    $stmt_avg = $conn->prepare("SELECT AVG(rating) as avg_r, COUNT(*) as total FROM reviews WHERE product_id = ?");
    $stmt_avg->bind_param("i", $product_id);
    $stmt_avg->execute();
    $rating_data = $stmt_avg->get_result()->fetch_assoc();
    $avg_rating = round($rating_data['avg_r'] ?? 0, 1);
    $total_reviews = $rating_data['total'] ?? 0;

    // جلب التعليقات
    $stmt_reviews = $conn->prepare("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = ? ORDER BY r.created_at DESC");
    $stmt_reviews->bind_param("i", $product_id);
    $stmt_reviews->execute();
    $result_reviews = $stmt_reviews->get_result();
    while ($row = $result_reviews->fetch_assoc()) {
        $reviews_list[] = $row;
        if (isset($_SESSION['user_id']) && $row['user_id'] == $_SESSION['user_id']) {
            $user_has_reviewed = true;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching reviews: " . $e->getMessage());
}

// ========== إحصائيات ==========
$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cart_count += isset($item['quantity']) ? $item['quantity'] : 1;
    }
}
$wishlist_count = isset($_SESSION['wishlist']) ? count($_SESSION['wishlist']) : 0;
$is_logged_in = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? '';

// ========== حساب الخصم ==========
$has_discount = isset($product['old_price']) && $product['old_price'] > 0 && $product['old_price'] > $product['price'];
$discount_percent = $has_discount ? round((1 - $product['price'] / $product['old_price']) * 100) : 0;

$product_image = !empty($product['image']) ? $product['image'] : 'assets/images/placeholder.jpg';
$stock_status = $product['stock_status'] ?? 'in_stock';
$is_in_wishlist = in_array($product_id, $_SESSION['wishlist'] ?? []);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> | متجري</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        /* ===== الأنماط الأساسية ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Tajawal', sans-serif; background: #f1f5f9; color: #0f172a; line-height: 1.7; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }

        /* ===== الهيدر ===== */
        .header { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.06); position: sticky; top: 0; z-index: 1000; border-bottom: 3px solid #2563eb; padding: 12px 5%; }
        .header-top { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 15px; }
        .logo { font-size: 28px; font-weight: 900; }
        .logo .mat { color: #0f172a; } .logo .jari { color: #2563eb; }
        .nav-links { display: flex; gap: 25px; list-style: none; }
        .nav-links a { font-weight: 500; color: #64748b; transition: 0.3s; font-size: 14px; }
        .nav-links a:hover { color: #2563eb; }
        .search-box { flex: 1; max-width: 400px; display: flex; border: 2px solid #e2e8f0; border-radius: 50px; overflow: hidden; background: #f1f5f9; }
        .search-box input { flex: 1; border: none; padding: 10px 20px; font-family: 'Tajawal', sans-serif; font-size: 14px; outline: none; background: transparent; }
        .search-box button { border: none; background: #2563eb; color: #fff; padding: 10px 22px; cursor: pointer; }
        .header-actions { display: flex; align-items: center; gap: 8px; }
        .icon-btn { position: relative; color: #0f172a; font-size: 20px; padding: 8px 12px; border-radius: 10px; background: transparent; border: none; cursor: pointer; }
        .icon-btn:hover { background: #eff6ff; color: #2563eb; }
        .badge { position: absolute; top: 0; right: 0; background: #ef4444; color: #fff; font-size: 10px; font-weight: 900; min-width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }

        /* ===== صفحة المنتج ===== */
        .product-page { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .product-container { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }

        /* ===== معرض الصور ===== */
        .product-gallery { position: relative; }
        .product-gallery .main-image { background: #f1f5f9; border-radius: 16px; padding: 30px; display: flex; align-items: center; justify-content: center; min-height: 400px; border: 1px solid #e2e8f0; }
        .product-gallery .main-image img { max-height: 400px; max-width: 100%; object-fit: contain; }
        .product-gallery .badge { position: absolute; top: 15px; left: 15px; padding: 6px 18px; border-radius: 50px; font-weight: 700; font-size: 12px; color: #fff; z-index: 5; }
        .badge-sale { background: #ef4444; } .badge-new { background: #2563eb; }
        .thumbnails { display: flex; gap: 12px; margin-top: 15px; flex-wrap: wrap; }
        .thumbnails img { width: 80px; height: 80px; object-fit: cover; border-radius: 10px; cursor: pointer; border: 2px solid #e2e8f0; transition: 0.3s; }
        .thumbnails img:hover, .thumbnails img.active { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.2); }

        /* ===== معلومات المنتج ===== */
        .product-info h1 { font-size: 28px; font-weight: 900; margin-bottom: 8px; }
        .product-info .rating { display: flex; align-items: center; gap: 12px; color: #64748b; font-size: 14px; margin-bottom: 12px; flex-wrap: wrap; }
        .product-info .rating .stars { color: #fbbf24; letter-spacing: 2px; font-size: 16px; }
        .product-info .rating .in-stock { color: #22c55e; font-weight: 700; background: #dcfce7; padding: 2px 14px; border-radius: 20px; font-size: 12px; }
        .product-info .price { display: flex; align-items: center; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .product-info .price .current { font-size: 32px; font-weight: 900; color: #0f172a; }
        .product-info .price .old { font-size: 20px; color: #64748b; text-decoration: line-through; }
        .product-info .price .discount { font-size: 13px; font-weight: 700; color: #22c55e; background: #dcfce7; padding: 3px 14px; border-radius: 20px; }
        .product-info .description { font-size: 15px; color: #64748b; line-height: 1.8; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        
        .select-finish { margin-bottom: 20px; }
        .finish-title { font-size: 14px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 10px; }
        .finish-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .finish-item { display: flex; align-items: center; gap: 10px; background: #f1f5f9; padding: 10px 14px; border-radius: 10px; font-weight: 500; font-size: 13px; }
        .finish-item i { font-size: 18px; color: #2563eb; }
        
        .product-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 15px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 30px; border-radius: 50px; font-weight: 700; font-size: 15px; transition: all 0.3s; cursor: pointer; border: none; font-family: inherit; }
        .btn-primary { background: #2563eb; color: #fff; } .btn-primary:hover { background: #1d4ed8; transform: translateY(-2px); box-shadow: 0 8px 25px rgba(37,99,235,0.3); }
        .btn-success { background: #22c55e; color: #fff; } .btn-success:hover { background: #16a34a; transform: translateY(-2px); }
        .btn-outline { background: transparent; color: #0f172a; border: 2px solid #e2e8f0; padding: 12px 16px; } .btn-outline:hover, .btn-outline.active { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
        
        .product-extras { display: flex; gap: 20px; flex-wrap: wrap; padding-top: 15px; border-top: 1px solid #e2e8f0; }
        .product-extras span { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; }
        .product-extras i { color: #2563eb; }

        /* ===== المواصفات ===== */
        .specifications-section { margin-top: 30px; background: #fff; border-radius: 16px; padding: 35px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .specifications-section h2 { font-size: 22px; font-weight: 900; margin-bottom: 18px; }
        .specs-container { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; }
        .spec-group h3 { font-size: 16px; font-weight: 700; margin-bottom: 10px; border-bottom: 2px solid #eff6ff; padding-bottom: 8px; }
        .spec-group ul { list-style: none; }
        .spec-group ul li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
        .spec-group ul li .key { color: #64748b; } .spec-group ul li .value { font-weight: 600; }

        /* ===== التقييمات ===== */
        .reviews-section { margin-top: 30px; background: #fff; border-radius: 16px; padding: 35px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .reviews-section h2 { font-size: 22px; font-weight: 900; margin-bottom: 20px; }
        .review-form { background: #f8fafc; border-radius: 12px; padding: 20px; margin-bottom: 30px; }
        .review-form textarea { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-family: inherit; min-height: 80px; margin-bottom: 12px; }
        .star-rating { display: flex; gap: 5px; margin-bottom: 15px; direction: ltr; justify-content: flex-end; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 1.5rem; color: #d1d5db; cursor: pointer; transition: 0.1s; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #f59e0b; }
        .review-item { border-bottom: 1px solid #e2e8f0; padding: 18px 0; }
        .review-item:last-child { border-bottom: none; }
        .review-header { display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 700; }
        .review-date { font-size: 0.8rem; color: #64748b; }
        .review-stars { color: #f59e0b; margin-bottom: 8px; }
        .review-text { color: #64748b; }

        /* ===== منتجات مشابهة ===== */
        .similar-products { margin-top: 50px; }
        .similar-products h2 { font-size: 24px; font-weight: 900; margin-bottom: 20px; }
        .similar-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .similar-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: 0.3s; border: 1px solid transparent; text-align: center; }
        .similar-card:hover { transform: translateY(-4px); border-color: #2563eb; }
        .similar-card .sim-image { background: #f1f5f9; height: 160px; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .similar-card .sim-info { padding: 12px 14px 16px; }
        .similar-card .sim-info h3 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        .similar-card .add-btn { width: 100%; padding: 7px; border: none; border-radius: 10px; background: #2563eb; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; margin-top: 8px; }

        /* ===== الفوتر ===== */
        footer { background: #0f172a; color: #94a3b8; padding: 50px 5% 30px; margin-top: 50px; }
        footer h3, footer h4 { color: #fff; }
        footer a { color: #94a3b8; } footer a:hover { color: #fff; }

        /* ===== استجابة ===== */
        @media (max-width: 992px) {
            .product-container { grid-template-columns: 45% 55%; gap: 20px; padding: 25px; }
            .product-gallery .main-image { min-height: 280px; }
            .nav-links { display: none; }
            .similar-grid, .specs-container { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .header-top { flex-wrap: wrap; }
            .search-box { order: 3; flex-basis: 100%; max-width: 100%; }
            .product-container { grid-template-columns: 1fr; padding: 18px; }
            .specs-container { grid-template-columns: 1fr; }
            .similar-grid { grid-template-columns: 1fr 1fr; gap: 15px; }
            .product-actions .btn { flex: 1; justify-content: center; }
        }
        @media (max-width: 480px) {
            .similar-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- الهيدر -->
<header class="header">
    <div class="header-top">
        <a href="index.php" class="logo"><span class="mat">Mat</span><span class="jari">jari</span></a>
        <ul class="nav-links">
            <li><a href="index.php">الرئيسية</a></li>
            <li><a href="offers.php">العروض</a></li>
            <li><a href="new-arrivals.php">وصل حديثاً</a></li>
            <li><a href="best-sellers.php">الأكثر مبيعاً</a></li>
        </ul>
        <form class="search-box" method="GET" action="search.php">
            <input type="text" name="q" placeholder="ابحث عن منتج...">
            <button type="submit"><i class="fas fa-search"></i></button>
        </form>
        <div class="header-actions">
            <?php if ($is_logged_in): ?>
                <a href="profile.php" class="icon-btn"><i class="fas fa-user"></i></a>
            <?php else: ?>
                <a href="login.php" class="icon-btn"><i class="fas fa-user"></i></a>
            <?php endif; ?>
            <a href="wishlist.php" class="icon-btn"><i class="fas fa-heart"></i><?php if ($wishlist_count > 0): ?><span class="badge"><?php echo $wishlist_count; ?></span><?php endif; ?></a>
            <a href="cart.php" class="icon-btn"><i class="fas fa-shopping-bag"></i><?php if ($cart_count > 0): ?><span class="badge"><?php echo $cart_count; ?></span><?php endif; ?></a>
        </div>
    </div>
</header>

<!-- صفحة المنتج -->
<main class="product-page">
    <div class="product-container">
        <!-- معرض الصور -->
        <div class="product-gallery">
            <div class="main-image">
                <img src="<?php echo htmlspecialchars($gallery_images[0] ?? $product_image); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" id="mainImage" onerror="this.src='https://via.placeholder.com/600x600/e2e8f0/64748b?text=Matjari'">
            </div>
            <div class="thumbnails">
                <?php 
                $has_thumbs = false;
                if (!empty($gallery_images)) {
                    foreach ($gallery_images as $index => $img) {
                        $img_safe = htmlspecialchars($img);
                        $active = $index === 0 ? 'active' : '';
                        echo '<img src="' . $img_safe . '" onclick="changeImage(\'' . $img_safe . '\', this)" class="' . $active . '">';
                        $has_thumbs = true;
                    }
                }
                ?>
            </div>
            <?php if ($has_discount && $discount_percent >= 20): ?>
                <span class="badge badge-sale">خصم <?php echo $discount_percent; ?>%</span>
            <?php elseif (isset($product['is_new']) && $product['is_new'] == 1): ?>
                <span class="badge badge-new">جديد</span>
            <?php endif; ?>
        </div>

        <!-- معلومات المنتج -->
        <div class="product-info">
            <h1><?php echo htmlspecialchars($product['name']); ?></h1>
            <div class="rating">
                <span class="stars">
                    <?php 
                    for ($i = 1; $i <= 5; $i++) {
                        echo ($i <= round($avg_rating)) ? '★' : '☆';
                    }
                    ?>
                </span>
                <span>(<?php echo $total_reviews; ?> تقييم)</span>
                <?php if ($stock_status == 'in_stock'): ?>
                    <span class="in-stock"><i class="fas fa-check-circle"></i> متوفر</span>
                <?php endif; ?>
            </div>

            <div class="price">
                <span class="current"><?php echo number_format($product['price'], 2); ?> ريال</span>
                <?php if ($has_discount): ?>
                    <span class="old"><?php echo number_format($product['old_price'], 2); ?> ريال</span>
                    <span class="discount">وفر <?php echo $discount_percent; ?>%</span>
                <?php endif; ?>
            </div>

            <div class="description"><?php echo nl2br(htmlspecialchars($product['description'] ?? 'وصف المنتج غير متوفر حالياً.')); ?></div>

            <?php if (!empty($product_features)): ?>
            <div class="select-finish">
                <div class="finish-title">اختر الميزات</div>
                <div class="finish-grid">
                    <?php foreach ($product_features as $feature): ?>
                        <div class="finish-item">
                            <i class="fas <?php echo htmlspecialchars($feature['icon'] ?? 'fa-star'); ?>"></i>
                            <div>
                                <div><?php echo htmlspecialchars($feature['title']); ?></div>
                                <div class="sub"><?php echo htmlspecialchars($feature['subtitle'] ?? ''); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="product-actions">
                <button class="btn btn-primary" onclick="addToCart()"><i class="fas fa-shopping-cart"></i> أضف للسلة</button>
                <button class="btn btn-success" onclick="buyNow()"><i class="fas fa-bolt"></i> اشترِ الآن</button>
                <button class="btn btn-outline <?php echo $is_in_wishlist ? 'active' : ''; ?>" onclick="toggleWishlist()" id="wishlistBtn"><i class="fas fa-heart"></i></button>
            </div>

            <div class="product-extras">
                <span><i class="fas fa-truck-fast"></i> توصيل مجاني</span>
                <span><i class="fas fa-shield"></i> ضمان سنتين</span>
            </div>
        </div>
    </div>

    <!-- المواصفات -->
    <?php if (!empty($specifications)): ?>
    <section class="specifications-section">
        <h2><i class="fas fa-microchip"></i> المواصفات التقنية</h2>
        <div class="specs-container">
            <?php 
            $chunk_size = ceil(count($specifications) / 3);
            $groups = array_chunk($specifications, $chunk_size);
            foreach ($groups as $group): ?>
                <div class="spec-group">
                    <h3><?php echo htmlspecialchars($group[0]['key'] ?? 'المواصفات'); ?></h3>
                    <ul>
                        <?php foreach ($group as $spec): ?>
                            <li><span class="key"><?php echo htmlspecialchars($spec['key']); ?></span><span class="value"><?php echo htmlspecialchars($spec['value']); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- التقييمات -->
    <section class="reviews-section">
        <h2>التقييمات والمراجعات (<?php echo $total_reviews; ?>)</h2>
        
        <?php if ($is_logged_in): ?>
            <?php if (!$user_has_reviewed): ?>
                <div class="review-form">
                    <h4>أضف تقييمك</h4>
                    <form id="reviewForm">
                        <div class="star-rating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required>
                                <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                            <?php endfor; ?>
                        </div>
                        <textarea name="comment" placeholder="اكتب تعليقك..." required></textarea>
                        <button type="submit" class="btn btn-primary">إرسال التقييم</button>
                    </form>
                </div>
            <?php else: ?>
                <p style="color:#64748b; margin-bottom:20px;">لقد قمت بتقييم هذا المنتج مسبقاً. شكراً لك!</p>
            <?php endif; ?>
        <?php else: ?>
            <p style="margin-bottom:20px;">يجب <a href="login.php" style="color:#2563eb;">تسجيل الدخول</a> لإضافة تقييم.</p>
        <?php endif; ?>

        <?php if (!empty($reviews_list)): ?>
            <?php foreach ($reviews_list as $review): ?>
                <div class="review-item">
                    <div class="review-header">
                        <span><?php echo htmlspecialchars($review['username']); ?></span>
                        <span class="review-date"><?php echo date('Y/m/d', strtotime($review['created_at'])); ?></span>
                    </div>
                    <div class="review-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star<?php echo $i <= $review['rating'] ? '' : ' far'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <p class="review-text"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align:center;color:#64748b;padding:20px;">لا توجد تقييمات بعد. كن أول من يقيم هذا المنتج!</p>
        <?php endif; ?>
    </section>

    <!-- منتجات مشابهة -->
    <?php if (!empty($similar_products)): ?>
    <section class="similar-products">
        <h2>🔄 أكمل مجموعتك</h2>
        <div class="similar-grid">
            <?php foreach ($similar_products as $similar): ?>
                <div class="similar-card">
                    <a href="product.php?id=<?php echo $similar['id']; ?>">
                        <div class="sim-image">
                            <img src="<?php echo htmlspecialchars($similar['image'] ?? 'assets/images/placeholder.jpg'); ?>" alt="<?php echo htmlspecialchars($similar['name']); ?>">
                        </div>
                    </a>
                    <div class="sim-info">
                        <a href="product.php?id=<?php echo $similar['id']; ?>"><h3><?php echo htmlspecialchars($similar['name']); ?></h3></a>
                        <div class="price">
                            <span class="current"><?php echo number_format($similar['price'], 2); ?> ريال</span>
                        </div>
                        <button class="add-btn" onclick="addSimilarToCart(<?php echo $similar['id']; ?>, this)"><i class="fas fa-plus"></i> أضف</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- الفوتر -->
<footer>
    <div style="max-width:1200px;margin:0 auto;display:flex;flex-wrap:wrap;justify-content:space-between;gap:40px;">
        <div style="flex:1;min-width:200px;">
            <h3><i class="fas fa-store" style="color:#2563eb;"></i> متجري</h3>
            <p style="font-size:14px;">وجهتك الأولى للتسوق الإلكتروني.</p>
        </div>
        <div style="flex:1;min-width:150px;">
            <h4>روابط سريعة</h4>
            <ul style="list-style:none;"><li><a href="index.php">الرئيسية</a></li><li><a href="about.php">من نحن</a></li></ul>
        </div>
        <div style="flex:1;min-width:150px;">
            <h4>خدمة العملاء</h4>
            <ul style="list-style:none;"><li><a href="privacy.php">سياسة الخصوصية</a></li><li><a href="contact.php">اتصل بنا</a></li></ul>
        </div>
        <div style="flex:1;min-width:180px;">
            <h4>تواصل معنا</h4>
            <ul style="list-style:none;"><li><i class="fas fa-envelope" style="color:#2563eb;"></i> info@matjari.com</li></ul>
        </div>
    </div>
    <div style="text-align:center;margin-top:30px;padding-top:20px;border-top:2px solid #1e293b;font-size:14px;">
        <p>متجري. جميع الحقوق محفوظة 2026 ©</p>
    </div>
</footer>

<script>
const productId = <?php echo $product_id; ?>;

function changeImage(imgSrc, element) {
    document.getElementById('mainImage').src = imgSrc;
    document.querySelectorAll('.thumbnails img').forEach(el => el.classList.remove('active'));
    if (element) element.classList.add('active');
}

function addToCart() {
    const btn = document.querySelector('.btn-primary');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإضافة...';
    btn.disabled = true;
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_cart', product_id: productId, quantity: 1 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i> تمت الإضافة!';
            btn.style.background = '#22c55e';
            const badge = document.querySelector('a[href="cart.php"] .badge');
            if (badge) badge.textContent = (parseInt(badge.textContent) || 0) + 1;
            setTimeout(() => { btn.innerHTML = originalText; btn.style.background = ''; btn.disabled = false; }, 2000);
        } else {
            alert(data.message);
            btn.innerHTML = originalText; btn.disabled = false;
        }
    })
    .catch(() => { btn.innerHTML = originalText; btn.disabled = false; });
}

function addSimilarToCart(pid, button) {
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_cart', product_id: pid, quantity: 1 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            button.innerHTML = '<i class="fas fa-check"></i> تمت';
            button.style.background = '#22c55e';
            setTimeout(() => { button.innerHTML = originalText; button.style.background = ''; button.disabled = false; }, 2000);
        } else {
            alert(data.message);
            button.innerHTML = originalText; button.disabled = false;
        }
    })
    .catch(() => { button.innerHTML = originalText; button.disabled = false; });
}

function buyNow() {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'add_to_cart', product_id: productId, quantity: 1 })
    })
    .then(r => r.json())
    .then(data => { if (data.success) window.location.href = 'checkout.php'; })
    .catch(() => alert('خطأ، يرجى المحاولة مرة أخرى'));
}

function toggleWishlist() {
    const btn = document.getElementById('wishlistBtn');
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'toggle_wishlist', product_id: productId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.classList.toggle('active');
            const badge = document.querySelector('a[href="wishlist.php"] .badge');
            if (badge) {
                let count = parseInt(badge.textContent) || 0;
                if (data.action === 'added') badge.textContent = count + 1;
                else { badge.textContent = count - 1; if (badge.textContent == 0) badge.remove(); }
            }
        }
    });
}

// إرسال التقييم
document.getElementById('reviewForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const rating = document.querySelector('input[name="rating"]:checked')?.value;
    const comment = document.querySelector('textarea[name="comment"]').value;
    
    if (!rating) { alert('يرجى اختيار تقييم'); return; }
    if (!comment.trim()) { alert('يرجى كتابة تعليق'); return; }

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'submit_review', rating: rating, comment: comment })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) window.location.reload();
    })
    .catch(() => alert('حدث خطأ، حاول لاحقاً'));
});
</script>
</body>
</html>
