<?php
session_start();
require_once 'config.php';

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($product_id === 0) {
    header('Location: index.php');
    exit;
}

// حذف تقييم
if (isset($_GET['delete_review']) && isset($_SESSION['user_id'])) {
    $review_id = intval($_GET['delete_review']);
    $stmt_del = $conn->prepare("DELETE FROM reviews WHERE id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $review_id, $_SESSION['user_id']);
    $stmt_del->execute();
    header("Location: product.php?id=$product_id");
    exit;
}

// تعديل تقييم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_review'])) {
    $review_id = intval($_POST['review_id']);
    $rating = intval($_POST['edit_rating'] ?? 0);
    $comment = trim($_POST['edit_comment'] ?? '');
    
    if ($rating >= 1 && $rating <= 5 && !empty($comment)) {
        $stmt_edit = $conn->prepare("UPDATE reviews SET rating = ?, comment = ? WHERE id = ? AND user_id = ?");
        $stmt_edit->bind_param("isii", $rating, $comment, $review_id, $_SESSION['user_id']);
        $stmt_edit->execute();
        header("Location: product.php?id=$product_id");
        exit;
    }
}

// جلب المنتج
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: index.php');
    exit;
}

$product = $result->fetch_assoc();

// حساب التقييم
$rating_query = $conn->query("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = $product_id");
$rating_data = $rating_query->fetch_assoc();
$avg_rating = round($rating_data['avg_rating'] ?? 0, 1);
$total_reviews = $rating_data['total_reviews'] ?? 0;

// إضافة تقييم جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!isset($_SESSION['user_id'])) {
        $error_msg = 'يجب تسجيل الدخول لإضافة تقييم';
    } else {
        $rating = intval($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        
        if ($rating < 1 || $rating > 5) {
            $error_msg = 'يرجى اختيار تقييم من 1 إلى 5';
        } elseif (empty($comment)) {
            $error_msg = 'يرجى كتابة تعليق';
        } else {
            $check = $conn->prepare("SELECT id FROM reviews WHERE product_id = ? AND user_id = ?");
            $check->bind_param("ii", $product_id, $_SESSION['user_id']);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error_msg = 'لقد قمت بتقييم هذا المنتج مسبقاً';
            } else {
                $stmt_review = $conn->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
                $stmt_review->bind_param("iiis", $product_id, $_SESSION['user_id'], $rating, $comment);
                if ($stmt_review->execute()) {
                    header("Location: product.php?id=$product_id");
                    exit;
                }
            }
        }
    }
}

// جلب التعليقات
$reviews = $conn->query("SELECT r.*, u.username FROM reviews r JOIN users u ON r.user_id = u.id WHERE r.product_id = $product_id ORDER BY r.created_at DESC");

// منتجات مشابهة
$stmt_similar = $conn->prepare("SELECT * FROM products WHERE category = ? AND id != ? LIMIT 4");
$stmt_similar->bind_param("si", $product['category'], $product_id);
$stmt_similar->execute();
$similar_products = $stmt_similar->get_result();

$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$username = $_SESSION['username'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) $cart_count += $item['quantity'] ?? 1;
}
$wishlist_count = 0;
if (isset($_SESSION['wishlist'])) $wishlist_count = count($_SESSION['wishlist']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> | متجري</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #eff6ff; --bg: #f8fafc; --white: #ffffff; --text: #1e293b; --text-light: #64748b; --border: #e2e8f0; --danger: #ef4444; --success: #10b981; --star: #f59e0b; --header-height: 60px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--bg); color: var(--text); line-height: 1.7; }
        
        .header { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 20px; height: var(--header-height); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .logo { font-size: 1.3rem; font-weight: 900; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 7px; }
        .header-actions { display: flex; align-items: center; gap: 8px; }
        .icon-btn { color: var(--text); text-decoration: none; font-size: 1.1rem; padding: 7px 9px; border-radius: 8px; position: relative; transition: all 0.2s; display: flex; align-items: center; }
        .icon-btn:hover { background: var(--bg); color: var(--primary); }
        .badge-counter { position: absolute; top: 0; right: 0; background: var(--danger); color: white; font-size: 0.5rem; font-weight: 900; min-width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .btn { padding: 8px 16px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px; border: none; cursor: pointer; font-family: inherit; }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); } .btn-outline:hover { background: var(--primary); color: white; }
        .btn-primary { background: var(--primary); color: white; } .btn-primary:hover { background: var(--primary-dark); }
        .btn-sm { padding: 6px 12px; font-size: 0.75rem; border-radius: 8px; }

        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .breadcrumb { margin-bottom: 20px; font-size: 0.9rem; color: var(--text-light); }
        .breadcrumb a { color: var(--primary); text-decoration: none; } .breadcrumb a:hover { text-decoration: underline; }

        .product-detail { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; background: var(--white); border-radius: 20px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 40px; }
        .product-image { border-radius: 16px; overflow: hidden; background: #f1f5f9; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; }
        .product-image img { width: 100%; height: 100%; object-fit: cover; }
        .product-info { display: flex; flex-direction: column; }
        .product-info .category { color: var(--primary); font-weight: 600; font-size: 0.85rem; margin-bottom: 10px; display: inline-block; background: var(--primary-light); padding: 5px 14px; border-radius: 20px; align-self: flex-start; }
        .product-info h1 { font-size: 1.8rem; font-weight: 800; margin-bottom: 15px; line-height: 1.3; }
        .rating-display { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .stars-display { color: var(--star); font-size: 1.1rem; }
        .rating-number { font-weight: 700; font-size: 1rem; }
        .rating-count { color: var(--text-light); font-size: 0.85rem; }
        .price { font-size: 2rem; font-weight: 900; color: var(--primary); margin-bottom: 20px; }
        .description { color: var(--text-light); line-height: 2; margin-bottom: 25px; font-size: 0.95rem; }
        
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-add-cart { flex: 1; min-width: 180px; padding: 14px 20px; background: var(--primary); color: white; border: none; border-radius: 12px; font-family: inherit; font-weight: 700; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s; }
        .btn-add-cart:hover { background: var(--primary-dark); }
        .btn-wishlist { padding: 14px 20px; background: transparent; color: var(--danger); border: 2px solid #fecaca; border-radius: 12px; font-family: inherit; font-weight: 700; font-size: 0.95rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s; white-space: nowrap; }
        .btn-wishlist:hover { background: #fef2f2; }
        .btn-wishlist.active { background: #fee2e2; border-color: var(--danger); }
        .btn-edit { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: #fef3c7; color: #92400e; border-radius: 6px; text-decoration: none; font-size: 0.75rem; font-weight: 700; margin-top: 15px; align-self: flex-start; }

        .reviews-section { background: var(--white); border-radius: 20px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); border: 1px solid var(--border); margin-bottom: 30px; }
        .reviews-section h2 { font-size: 1.3rem; font-weight: 800; margin-bottom: 25px; }
        
        .review-form { background: #f8fafc; border-radius: 12px; padding: 20px; margin-bottom: 30px; }
        .review-form h4 { margin-bottom: 15px; font-size: 1rem; }
        .star-rating { display: flex; gap: 5px; margin-bottom: 15px; direction: ltr; justify-content: flex-end; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 1.5rem; color: #d1d5db; cursor: pointer; transition: all 0.1s; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: var(--star); }
        .review-form textarea { width: 100%; padding: 12px; border: 2px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 0.9rem; resize: vertical; min-height: 80px; margin-bottom: 12px; }
        .review-form textarea:focus { outline: none; border-color: var(--primary); }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 15px; font-weight: 600; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-success { background: #d1fae5; color: #065f46; }

        .review-item { border-bottom: 1px solid var(--border); padding: 18px 0; }
        .review-item:last-child { border-bottom: none; }
        .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .review-author { font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 10px; }
        .review-date { font-size: 0.8rem; color: var(--text-light); }
        .review-stars { color: var(--star); margin-bottom: 8px; font-size: 0.9rem; }
        .review-text { color: var(--text-light); line-height: 1.8; }
        .review-actions { display: flex; gap: 8px; margin-top: 8px; }
        .btn-edit-review, .btn-delete-review { padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; cursor: pointer; text-decoration: none; border: none; font-family: inherit; }
        .btn-edit-review { background: #eff6ff; color: #2563eb; }
        .btn-edit-review:hover { background: #dbeafe; }
        .btn-delete-review { background: #fee2e2; color: #ef4444; }
        .btn-delete-review:hover { background: #fecaca; }

        .edit-form { background: #f8fafc; border-radius: 10px; padding: 15px; margin-top: 10px; display: none; }
        .edit-form.active { display: block; }

        .section-title { font-size: 1.3rem; font-weight: 800; margin-bottom: 20px; }
        .similar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; }
        .similar-card { background: var(--white); border-radius: 12px; overflow: hidden; border: 1px solid var(--border); transition: all 0.2s; text-decoration: none; color: var(--text); }
        .similar-card:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .similar-card img { width: 100%; aspect-ratio: 1; object-fit: cover; }
        .similar-card .info { padding: 12px; text-align: center; }
        .similar-card .info h4 { font-size: 0.9rem; font-weight: 700; margin-bottom: 4px; }
        .similar-card .info .price { font-weight: 800; color: var(--primary); font-size: 1rem; }

        .footer { background: var(--white); border-top: 1px solid var(--border); padding: 25px 20px; text-align: center; font-size: 0.85rem; color: var(--text-light); margin-top: 50px; }

        @media (max-width: 768px) { 
            .product-detail { grid-template-columns: 1fr; padding: 20px; } 
            .product-info h1 { font-size: 1.3rem; } 
            .price { font-size: 1.5rem; } 
            .action-buttons { flex-direction: column; } 
        }
    </style>
</head>
<body>

<div style="background:#fef3c7;color:#92400e;text-align:center;padding:10px;font-size:0.85rem;font-weight:600;border-bottom:1px solid #fde68a;">
    ⚠️ موقع تجريبي | هذا المتجر غير فعال حالياً | لشراء الموقع أو الاستفسار: 
    <a href="mailto:benmirikram74@gmail.com" style="color:#2563eb;font-weight:700;">benmirikram74@gmail.com</a>
</div>


<header class="header">
    <a href="index.php" class="logo"><i class="fas fa-store"></i> متجري</a>
    <div class="header-actions">
        <a href="wishlist.php" class="icon-btn"><i class="fas fa-heart"></i><?php if($wishlist_count>0): ?><span class="badge-counter"><?php echo $wishlist_count; ?></span><?php endif; ?></a>
        <a href="cart.php" class="icon-btn"><i class="fas fa-shopping-cart"></i><?php if($cart_count>0): ?><span class="badge-counter"><?php echo $cart_count; ?></span><?php endif; ?></a>
        <?php if($is_logged_in): ?>
            <a href="logout.php" class="icon-btn" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i></a>
        <?php else: ?>
            <a href="login.php" class="btn btn-outline">دخول</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <div class="breadcrumb">
        <a href="index.php">الرئيسية</a> &gt;
        <a href="index.php?cat=<?php echo urlencode($product['category']); ?>"><?php echo htmlspecialchars($product['category']); ?></a> &gt;
        <?php echo htmlspecialchars($product['name']); ?>
    </div>

    <div class="product-detail">
        <div class="product-image">
            <img src="<?php echo !empty($product['image']) ? $product['image'] : 'images/placeholder.png'; ?>" 
                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                 onerror="this.src='images/placeholder.png';">
        </div>

        <div class="product-info">
            <span class="category"><?php echo htmlspecialchars($product['category']); ?></span>
            <h1><?php echo htmlspecialchars($product['name']); ?></h1>
            
            <div class="rating-display">
                <div class="stars-display">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <?php if ($i <= round($avg_rating)): ?>
                            <i class="fas fa-star"></i>
                        <?php elseif ($i - 0.5 <= $avg_rating): ?>
                            <i class="fas fa-star-half-alt"></i>
                        <?php else: ?>
                            <i class="far fa-star"></i>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <span class="rating-number"><?php echo $avg_rating; ?></span>
                <span class="rating-count">(<?php echo $total_reviews; ?> تقييم)</span>
            </div>

            <div class="price"><?php echo number_format($product['price'], 2); ?> ريال</div>
            
            <?php if(!empty($product['description'])): ?>
                <p class="description"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
            <?php endif; ?>

            <div class="action-buttons">
                <button class="btn-add-cart" onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', <?php echo $product['price']; ?>)">
                    <i class="fas fa-cart-plus"></i> أضف إلى السلة
                </button>
                <button class="btn-wishlist <?php echo (isset($_SESSION['wishlist']) && in_array($product['id'], $_SESSION['wishlist'])) ? 'active' : ''; ?>" 
                        onclick="toggleWishlist(<?php echo $product['id']; ?>, this)">
                    <i class="fas fa-heart"></i> المفضلة
                </button>
            </div>

            <?php if($is_admin): ?>
                <a href="admin/edit_product.php?id=<?php echo $product['id']; ?>" class="btn-edit">
                    <i class="fas fa-edit"></i> تعديل المنتج
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="reviews-section">
        <h2>التقييمات والمراجعات (<?php echo $total_reviews; ?>)</h2>
        
        <?php if ($is_logged_in): ?>
            <div class="review-form">
                <h4>أضف تقييمك</h4>
                <?php if (isset($error_msg)): ?><div class="alert alert-error"><?php echo $error_msg; ?></div><?php endif; ?>
                <form method="POST">
                    <div class="star-rating">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required>
                            <label for="star<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                        <?php endfor; ?>
                    </div>
                    <textarea name="comment" placeholder="اكتب تعليقك عن المنتج..." required></textarea>
                    <button type="submit" name="submit_review" class="btn btn-primary">إرسال التقييم</button>
                </form>
            </div>
        <?php else: ?>
            <div class="review-form" style="text-align:center;">
                <p>يجب <a href="login.php" style="color:var(--primary);">تسجيل الدخول</a> لإضافة تقييم</p>
            </div>
        <?php endif; ?>

        <?php if ($reviews && $reviews->num_rows > 0): ?>
            <?php while ($review = $reviews->fetch_assoc()): ?>
                <div class="review-item" id="review-<?php echo $review['id']; ?>">
                    <div class="review-header">
                        <span class="review-author">
                            <?php echo htmlspecialchars($review['username']); ?>
                            <?php if ($review['user_id'] == $user_id): ?>
                                <span style="background:#eff6ff;color:#2563eb;font-size:0.7rem;padding:2px 8px;border-radius:10px;">تعليقك</span>
                            <?php endif; ?>
                        </span>
                        <span class="review-date"><?php echo date('Y/m/d', strtotime($review['created_at'])); ?></span>
                    </div>
                    <div class="review-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star<?php echo $i <= $review['rating'] ? '' : ' far'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <p class="review-text"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                    
                    <?php if ($review['user_id'] == $user_id): ?>
                        <div class="review-actions">
                            <button class="btn-edit-review" onclick="toggleEditForm(<?php echo $review['id']; ?>)">
                                <i class="fas fa-edit"></i> تعديل
                            </button>
                            <a href="?delete_review=<?php echo $review['id']; ?>" class="btn-delete-review" 
                               onclick="return confirm('هل أنت متأكد من حذف تقييمك؟')">
                                <i class="fas fa-trash"></i> حذف
                            </a>
                        </div>
                        
                        <div class="edit-form" id="edit-form-<?php echo $review['id']; ?>">
                            <form method="POST">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <div class="star-rating">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <input type="radio" id="edit-star-<?php echo $review['id']; ?>-<?php echo $i; ?>" name="edit_rating" value="<?php echo $i; ?>" <?php echo $review['rating'] == $i ? 'checked' : ''; ?>>
                                        <label for="edit-star-<?php echo $review['id']; ?>-<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                    <?php endfor; ?>
                                </div>
                                <textarea name="edit_comment" required><?php echo htmlspecialchars($review['comment']); ?></textarea>
                                <button type="submit" name="edit_review" class="btn btn-primary btn-sm">حفظ التعديل</button>
                                <button type="button" class="btn btn-outline btn-sm" onclick="toggleEditForm(<?php echo $review['id']; ?>)" style="margin-right:8px;">إلغاء</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="text-align:center;color:var(--text-light);padding:20px;">لا توجد تقييمات بعد. كن أول من يقيم هذا المنتج!</p>
        <?php endif; ?>
    </div>

    <?php if($similar_products && $similar_products->num_rows > 0): ?>
        <h2 class="section-title">منتجات مشابهة</h2>
        <div class="similar-grid">
            <?php while($sim = $similar_products->fetch_assoc()): ?>
                <a href="product.php?id=<?php echo $sim['id']; ?>" class="similar-card">
                    <img src="<?php echo !empty($sim['image']) ? $sim['image'] : 'images/placeholder.png'; ?>" 
                         alt="<?php echo htmlspecialchars($sim['name']); ?>"
                         onerror="this.src='images/placeholder.png';">
                    <div class="info">
                        <h4><?php echo htmlspecialchars($sim['name']); ?></h4>
                        <span class="price"><?php echo number_format($sim['price'], 2); ?> ريال</span>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<footer class="footer">
    <p>© 2026 Developed by <strong>BEN MIR IKRAM</strong>. All rights reserved.</p>
</footer>

<script>
    function toggleEditForm(reviewId) {
        const form = document.getElementById('edit-form-' + reviewId);
        form.classList.toggle('active');
    }

    function addToCart(productId, productName, price) {
        fetch('add_to_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + productId + '&quantity=1'
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { alert('✅ تمت إضافة "' + productName + '" إلى السلة!'); location.reload(); }
            else { alert('❌ ' + (d.message || 'خطأ')); }
        })
        .catch(() => alert('❌ خطأ في الاتصال'));
    }

    function toggleWishlist(productId, btn) {
        fetch('toggle_wishlist.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'product_id=' + productId
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { btn.classList.toggle('active'); location.reload(); }
            else { alert('❌ ' + (d.message || 'يجب تسجيل الدخول')); }
        });
    }
</script>
</body>
</html>
