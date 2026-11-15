<?php
session_start();
require_once 'config.php';

// دالة مساعدة للرسائل (بديل عن الدوال غير المعرفة)
if (!function_exists('setMessage')) {
    function setMessage($type, $text) {
        $_SESSION['message'] = ['type' => $type, 'text' => $text];
    }
}
if (!function_exists('getMessage')) {
    function getMessage() {
        if (isset($_SESSION['message'])) {
            $msg = $_SESSION['message'];
            unset($_SESSION['message']);
            return $msg;
        }
        return null;
    }
}
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

// جلب محتويات السلة
$cart_items = [];
$total = 0;

if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
    $product_ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    
    $sql = "SELECT * FROM products WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($product = $result->fetch_assoc()) {
        $product_id = $product['id'];
        $quantity = $_SESSION['cart'][$product_id]['quantity'] ?? 1;
        $price = $product['sale_price'] ?? $product['price'];
        $subtotal = $price * $quantity;
        $total += $subtotal;
        
        $cart_items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => $subtotal
        ];
    }
    $stmt->close();
}

// تحديث الكميات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart']) && isset($_POST['quantities'])) {
    foreach ($_POST['quantities'] as $product_id => $quantity) {
        $product_id = intval($product_id);
        $quantity = max(0, intval($quantity));
        if ($quantity > 0) {
            $_SESSION['cart'][$product_id]['quantity'] = $quantity;
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
    }
    setMessage('success', 'تم تحديث السلة بنجاح');
    redirect('cart.php');
}

// حذف منتج
if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    unset($_SESSION['cart'][$remove_id]);
    setMessage('success', 'تم حذف المنتج من السلة');
    redirect('cart.php');
}

// مسح السلة
if (isset($_GET['clear'])) {
    unset($_SESSION['cart']);
    setMessage('success', 'تم مسح السلة');
    redirect('cart.php');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سلة التسوق | متجري</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --danger: #ef4444;
            --success: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .page-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 900;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .page-title i {
            color: var(--primary);
        }

        .cart-count-header {
            color: var(--text-light);
            font-size: 1rem;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .cart-layout {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }

        .cart-items {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .cart-item {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 20px;
            display: flex;
            gap: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .cart-item:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .cart-item-image {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            background: #f1f5f9;
        }

        .cart-item-details {
            flex: 1;
        }

        .cart-item-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .cart-item-name a {
            color: var(--text);
            text-decoration: none;
        }

        .cart-item-name a:hover {
            color: var(--primary);
        }

        .cart-item-category {
            color: var(--text-light);
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .cart-item-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .cart-item-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .quantity-input {
            width: 70px;
            padding: 8px;
            border: 2px solid var(--border);
            border-radius: 8px;
            text-align: center;
            font-family: 'Tajawal', sans-serif;
            font-size: 1rem;
        }

        .quantity-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-family: 'Tajawal', sans-serif;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text);
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .cart-summary {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .summary-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            color: var(--text-light);
        }

        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
            border-top: 2px solid var(--border);
            padding-top: 15px;
            margin-top: 15px;
        }

        .summary-row.total .amount {
            color: var(--primary);
        }

        .checkout-btn {
            width: 100%;
            padding: 15px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-family: 'Tajawal', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.3s;
            display: block;
            text-align: center;
            text-decoration: none;
        }

        .checkout-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(37,99,235,0.3);
        }

        .empty-cart {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-cart i {
            font-size: 5rem;
            color: var(--text-light);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-cart h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .empty-cart p {
            color: var(--text-light);
            margin-bottom: 20px;
        }

        .continue-shopping {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .cart-layout {
                grid-template-columns: 1fr;
            }
            
            .cart-item {
                flex-direction: column;
            }
            
            .cart-item-image {
                width: 100%;
                height: 200px;
            }

            .page-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-shopping-cart"></i>
            سلة التسوق
        </h1>
        <span class="cart-count-header">
            <?php echo count($cart_items); ?> منتجات في السلة
        </span>
    </div>

    <?php 
    $message = getMessage();
    if ($message): ?>
        <div class="alert alert-<?php echo $message['type']; ?>">
            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message['text']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cart_items)): ?>
        <div class="cart-layout">
            <div class="cart-items">
                <form method="POST" id="cartForm">
                    <?php foreach ($cart_items as $item): 
                        $product = $item['product'];
                    ?>
                        <div class="cart-item">
                            <img src="<?php echo htmlspecialchars($product['image'] ?? 'placeholder.jpg'); ?>" 
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 class="cart-item-image"
                                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22><rect fill=%22%23e2e8f0%22 width=%22120%22 height=%22120%22/></svg>'">
                            
                            <div class="cart-item-details">
                                <h3 class="cart-item-name">
                                    <a href="product.php?id=<?php echo (int)$product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </a>
                                </h3>
                                <div class="cart-item-category">
                                    <?php echo htmlspecialchars($product['category'] ?? ''); ?>
                                </div>
                                <div class="cart-item-price">
                                    <?php echo number_format($item['price'], 2); ?> ريال
                                </div>
                                
                                <div class="cart-item-actions">
                                    <input type="number" 
                                           name="quantities[<?php echo (int)$product['id']; ?>]" 
                                           value="<?php echo (int)$item['quantity']; ?>" 
                                           min="1" 
                                           max="<?php echo isset($product['quantity']) ? (int)$product['quantity'] : 99; ?>"
                                           class="quantity-input">
                                    
                                    <a href="?remove=<?php echo (int)$product['id']; ?>" 
                                       class="btn btn-danger"
                                       onclick="return confirm('هل أنت متأكد من حذف هذا المنتج؟')">
                                        <i class="fas fa-trash"></i> حذف
                                    </a>
                                </div>
                            </div>
                            
                            <div style="text-align: left; font-weight: 700; color: var(--primary);">
                                <?php echo number_format($item['subtotal'], 2); ?> ريال
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
                        <button type="submit" name="update_cart" class="btn btn-primary">
                            <i class="fas fa-sync"></i> تحديث السلة
                        </button>
                        <a href="?clear=1" class="btn btn-outline" onclick="return confirm('هل أنت متأكد من مسح السلة؟')">
                            <i class="fas fa-trash"></i> مسح السلة
                        </a>
                        <a href="index.php" class="btn btn-outline">
                            <i class="fas fa-arrow-right"></i> متابعة التسوق
                        </a>
                    </div>
                </form>
            </div>

            <div class="cart-summary">
                <h3 class="summary-title">
                    <i class="fas fa-receipt"></i> ملخص الطلب
                </h3>
                
                <div class="summary-row">
                    <span>المجموع الفرعي</span>
                    <span><?php echo number_format($total, 2); ?> ريال</span>
                </div>
                <div class="summary-row">
                    <span>الضريبة (15%)</span>
                    <span><?php echo number_format($total * 0.15, 2); ?> ريال</span>
                </div>
                <div class="summary-row">
                    <span>الشحن</span>
                    <span><?php echo $total >= 300 ? 'مجاني' : number_format(25, 2) . ' ريال'; ?></span>
                </div>
                
                <?php 
                $tax = $total * 0.15;
                $shipping = $total >= 300 ? 0 : 25;
                $grand_total = $total + $tax + $shipping;
                ?>
                
                <div class="summary-row total">
                    <span>الإجمالي</span>
                    <span class="amount"><?php echo number_format($grand_total, 2); ?> ريال</span>
                </div>
                
                <a href="checkout.php" class="checkout-btn">
                    <i class="fas fa-lock"></i> إتمام الشراء
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <h2>السلة فارغة</h2>
            <p>لم تقم بإضافة أي منتجات للسلة بعد</p>
            <a href="index.php" class="continue-shopping">
                <i class="fas fa-arrow-right"></i> تصفح المنتجات
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
