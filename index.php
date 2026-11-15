<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'config.php';

// جلب البيانات
$category = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 12;

$sql = "SELECT * FROM products WHERE 1=1";
$count_sql = "SELECT COUNT(*) as total FROM products WHERE 1=1";
$params = [];
$types = "";

if ($category != '') {
    $sql .= " AND category = ?";
    $count_sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

if ($search_query != '') {
    $sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $count_sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($min_price > 0) {
    $sql .= " AND price >= ?";
    $count_sql .= " AND price >= ?";
    $params[] = $min_price;
    $types .= "d";
}

if ($max_price > 0) {
    $sql .= " AND price <= ?";
    $count_sql .= " AND price <= ?";
    $params[] = $max_price;
    $types .= "d";
}

switch ($sort) {
    case 'price_low': $sql .= " ORDER BY price ASC"; break;
    case 'price_high': $sql .= " ORDER BY price DESC"; break;
    case 'name_asc': $sql .= " ORDER BY name ASC"; break;
    case 'name_desc': $sql .= " ORDER BY name DESC"; break;
    default: $sql .= " ORDER BY id DESC"; break;
}

$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_products = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_products / $per_page);

$offset = ($page - 1) * $per_page;
$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$predefined_categories = [
    'إلكترونيات' => '💻', 'هواتف ذكية وإكسسوارات' => '📱', 'كمبيوتر ولابتوب' => '🖥️',
    'ألعاب فيديو' => '🎮', 'ألعاب' => '🧸', 'أطفال ورضع' => '🍼',
    'صيدلية وصحة وعافية' => '⚕️', 'عناية شخصية' => '🧼', 'تجميل' => '💄',
    'عطور' => '🌸', 'سيارات وإطارات' => '🚗', 'مستلزمات منزلية' => '🧻',
    'منزل، أثاث وأجهزة' => '🛋️', 'أدوات المطبخ' => '🍳', 'تحسين المنزل' => '🛠️',
    'حديقة وفناء' => '🪴', 'رياضة وخارج المنزل' => '⚽', 'حيوانات أليفة' => '🐾',
    'أدوات مدرسية ومكتبية' => '📚', 'فنون وحرف وحفلات' => '🎨',
    'ملابس، أحذية وإكسسوارات' => '👕', 'ساعات ومجوهرات' => '⌚',
    'أفلام وتلفزيون' => '🎬', 'موسيقى' => '🎵', 'كتب' => '📖',
    'عروض وتوفير' => '🏷️', 'بقالة' => '🛒', 'مواسم واحتفالات' => '🎉', 'بطاقات هدايا' => '🎁'
];

$cat_counts = [];
$cat_result = $conn->query("SELECT category, COUNT(*) as count FROM products WHERE category IS NOT NULL AND category != '' GROUP BY category");
if ($cat_result) {
    while ($row = $cat_result->fetch_assoc()) {
        $cat_counts[$row['category']] = $row['count'];
    }
}

$categories = [];
foreach ($predefined_categories as $cat_name => $icon) {
    $categories[] = ['category' => $cat_name, 'icon' => $icon, 'count' => isset($cat_counts[$cat_name]) ? $cat_counts[$cat_name] : 0];
}
foreach ($cat_counts as $db_cat => $count) {
    if (!array_key_exists($db_cat, $predefined_categories)) {
        $categories[] = ['category' => $db_cat, 'icon' => '📌', 'count' => $count];
    }
}

$cart_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) { $cart_count += $item['quantity'] ?? 1; }
}
$wishlist_count = 0;
if (isset($_SESSION['wishlist'])) { $wishlist_count = count($_SESSION['wishlist']); }

$is_logged_in = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $category ? htmlspecialchars($category) . ' | ' : ''; ?>متجري</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #eff6ff;
            --bg: #f8fafc; --white: #ffffff; --text: #1e293b; --text-light: #64748b;
            --border: #e2e8f0; --danger: #ef4444; --success: #10b981; --warning: #f59e0b;
            --shadow: 0 1px 3px rgba(0,0,0,0.06); --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
            --shadow-lg: 0 10px 30px rgba(0,0,0,0.1); --sidebar-width: 280px;
            --userbar-width: 280px; --header-height: 60px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Tajawal', sans-serif; background: var(--bg); color: var(--text); line-height: 1.7; overflow-x: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        .header { background: var(--white); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow); height: var(--header-height); }
        .header-inner { max-width: 100%; margin: 0 auto; padding: 0 20px; height: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .logo { font-size: 1.3rem; font-weight: 900; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 7px; white-space: nowrap; order: 2; }
        .logo i { font-size: 1.4rem; }
        .search-box { flex: 1; max-width: 500px; display: flex; border: 2px solid var(--border); border-radius: 50px; overflow: hidden; transition: all 0.2s; background: var(--bg); order: 3; }
        .search-box:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
        .search-box input { flex: 1; border: none; padding: 8px 15px; font-family: 'Tajawal', sans-serif; font-size: 0.9rem; outline: none; background: transparent; min-width: 0; }
        .search-box button { border: none; background: var(--primary); color: white; padding: 8px 16px; cursor: pointer; font-size: 0.95rem; transition: all 0.2s; }
        .search-box button:hover { background: var(--primary-dark); }
        .header-actions { display: flex; align-items: center; gap: 5px; order: 4; }
        .icon-btn { color: var(--text); text-decoration: none; font-size: 1.1rem; padding: 7px 9px; border-radius: 8px; position: relative; transition: all 0.2s; display: flex; align-items: center; border: none; background: transparent; cursor: pointer; font-family: 'Tajawal', sans-serif; }
        .icon-btn:hover { background: var(--bg); color: var(--primary); }
        .badge-counter { position: absolute; top: 0; right: 0; background: var(--danger); color: white; font-size: 0.5rem; font-weight: 900; min-width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .btn { padding: 8px 15px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 0.8rem; transition: all 0.2s; white-space: nowrap; font-family: 'Tajawal', sans-serif; display: inline-flex; align-items: center; gap: 4px; border: none; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; } .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); } .btn-outline:hover { background: var(--primary); color: white; }
        .menu-toggle-right { display: none; background: var(--primary); color: white; border: none; width: 38px; height: 38px; border-radius: 10px; font-size: 1.1rem; cursor: pointer; align-items: center; justify-content: center; order: 1; }
        .menu-toggle-left { display: none; background: var(--primary); color: white; border: none; width: 38px; height: 38px; border-radius: 10px; font-size: 1.1rem; cursor: pointer; align-items: center; justify-content: center; order: 5; }

        .main-container { display: flex; min-height: calc(100vh - var(--header-height)); }
        .sidebar-right { width: var(--sidebar-width); background: var(--white); border-left: 1px solid var(--border); overflow-y: auto; flex-shrink: 0; position: sticky; top: var(--header-height); height: calc(100vh - var(--header-height)); padding: 20px; order: 3; }
        .sidebar-title { font-size: 1rem; font-weight: 900; margin-bottom: 15px; color: var(--text); display: flex; align-items: center; gap: 8px; padding-bottom: 10px; border-bottom: 2px solid var(--primary-light); position: sticky; top: 0; background: var(--white); z-index: 5; }
        .cat-list { list-style: none; }
        .cat-list li { margin-bottom: 2px; }
        .cat-list a { display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; text-decoration: none; color: var(--text); border-radius: 8px; transition: all 0.2s; font-size: 0.85rem; font-weight: 500; }
        .cat-list a .cat-name { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
        .cat-list a .cat-name span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cat-list a:hover, .cat-list a.active { background: var(--primary-light); color: var(--primary-dark); font-weight: 700; transform: translateX(-3px); }
        .cat-count { font-size: 0.7rem; background: var(--bg); padding: 2px 7px; border-radius: 12px; color: var(--text-light); font-weight: 700; flex-shrink: 0; }
        .cat-list a:hover .cat-count, .cat-list a.active .cat-count { background: var(--white); color: var(--primary); }

        .sidebar-left { width: var(--userbar-width); background: var(--white); border-right: 1px solid var(--border); overflow-y: auto; flex-shrink: 0; position: sticky; top: var(--header-height); height: calc(100vh - var(--header-height)); padding: 20px; order: 1; }
        .user-section { text-align: center; padding-bottom: 15px; margin-bottom: 15px; border-bottom: 1px solid var(--border); }
        .user-avatar { width: 70px; height: 70px; border-radius: 50%; background: var(--primary-light); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 2rem; color: var(--primary); }
        .user-name { font-weight: 900; font-size: 1rem; color: var(--text); margin-bottom: 3px; }
        .user-role { font-size: 0.75rem; color: var(--text-light); margin-bottom: 10px; }
        .user-menu-list { list-style: none; }
        .user-menu-list li { margin-bottom: 2px; }
        .user-menu-list a, .user-menu-list button { display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-decoration: none; color: var(--text); border-radius: 8px; transition: all 0.2s; font-size: 0.85rem; font-weight: 500; width: 100%; border: none; background: transparent; cursor: pointer; font-family: 'Tajawal', sans-serif; text-align: right; }
        .user-menu-list a:hover, .user-menu-list button:hover { background: var(--primary-light); color: var(--primary); font-weight: 700; }
        .user-menu-list .divider { border-top: 1px solid var(--border); margin: 8px 0; }
        .text-danger { color: var(--danger) !important; }
        .text-warning { color: var(--warning) !important; }

        .content-area { flex: 1; padding: 20px 25px; min-width: 0; overflow-y: auto; order: 2; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; background: var(--white); padding: 12px 20px; border-radius: 12px; box-shadow: var(--shadow); }
        .toolbar-text { color: var(--text-light); font-size: 0.85rem; font-weight: 500; }
        .toolbar select { padding: 8px 30px 8px 12px; border: 2px solid var(--border); border-radius: 8px; font-family: 'Tajawal', sans-serif; font-size: 0.85rem; font-weight: 600; cursor: pointer; appearance: none; -webkit-appearance: none; background: var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E") no-repeat left 10px center; }
        .page-title { font-size: 1.4rem; font-weight: 900; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; }
        .product-card { background: var(--white); border-radius: 14px; overflow: hidden; border: 1px solid var(--border); transition: all 0.3s; box-shadow: var(--shadow); display: flex; flex-direction: column; }
        .product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--primary-light); }
        .product-img { position: relative; aspect-ratio: 4/3; overflow: hidden; background: #f1f5f9; }
        .product-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s; }
        .product-card:hover .product-img img { transform: scale(1.06); }
        .wishlist-btn { position: absolute; top: 8px; left: 8px; z-index: 2; background: rgba(255,255,255,0.9); border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 0.9rem; box-shadow: var(--shadow-md); transition: all 0.3s; display: flex; align-items: center; justify-content: center; color: #94a3b8; }
        .wishlist-btn:hover { transform: scale(1.1); } .wishlist-btn.active { color: var(--danger); }
        .product-body { padding: 14px; flex: 1; display: flex; flex-direction: column; }
        .product-cat { font-size: 0.7rem; color: var(--primary); font-weight: 700; margin-bottom: 5px; }
        .product-name { font-size: 0.95rem; font-weight: 800; margin-bottom: 6px; line-height: 1.3; }
        .product-name a { color: var(--text); text-decoration: none; } .product-name a:hover { color: var(--primary); }
        .price-row { display: flex; align-items: center; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
        .price { font-size: 1.1rem; font-weight: 900; color: var(--primary); }
        .btn-add { width: 100%; padding: 10px; background: var(--primary-light); color: var(--primary-dark); border: 2px solid transparent; border-radius: 10px; font-family: 'Tajawal', sans-serif; font-weight: 800; font-size: 0.82rem; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-add:hover { background: var(--primary); color: white; }
        .btn-edit-admin { display: inline-flex; align-items: center; gap: 4px; padding: 5px 8px; background: var(--warning); color: #000; border-radius: 6px; text-decoration: none; font-size: 0.68rem; font-weight: 700; margin-top: 6px; transition: all 0.2s; justify-content: center; }
        .btn-edit-admin:hover { background: #e5a50a; }
        .empty-state { text-align: center; padding: 50px 20px; background: var(--white); border-radius: 14px; color: var(--text-light); box-shadow: var(--shadow); }
        .empty-state i { font-size: 3.5rem; opacity: 0.2; margin-bottom: 12px; display: block; }
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 30px; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; border-radius: 8px; text-decoration: none; color: var(--text); font-weight: 700; font-size: 0.85rem; border: 2px solid var(--border); background: var(--white); transition: all 0.2s; }
        .pagination a:hover, .pagination .active { background: var(--primary); color: white; border-color: var(--primary); }

        .overlay { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); z-index: 150; }
        .mobile-sidebar { position: fixed; top: 0; width: 300px; max-width: 85vw; height: 100vh; height: 100dvh; background: var(--white); z-index: 200; overflow-y: auto; transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); padding: 20px; display: none; }
        .mobile-sidebar-right { right: 0; transform: translateX(100%); } .mobile-sidebar-right.open { transform: translateX(0); display: block; }
        .mobile-sidebar-left { left: 0; transform: translateX(-100%); } .mobile-sidebar-left.open { transform: translateX(0); display: block; }
        .mobile-sidebar .sb-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid var(--primary-light); }
        .mobile-sidebar .sb-header h3 { font-size: 1rem; font-weight: 900; display: flex; align-items: center; gap: 8px; }
        .mobile-sidebar .sb-close { background: #fee2e2; border: none; color: #dc2626; width: 32px; height: 32px; border-radius: 8px; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }

    /* =====================================================
   FOOTER STYLES
   ===================================================== */

.footer {
    border-top: 1px solid var(--border);
    padding: 35px 24px 25px;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.85rem;
    background: var(--bg);
    margin-top: 40px;
}

.footer .footer-content {
    max-width: 800px;
    margin: 0 auto;
}

.footer .footer-content h3 {
    font-size: 1.3rem;
    font-weight: 800;
    margin-bottom: 2px;
    color: var(--text);
}

.footer .footer-content h3 span {
    color: var(--primary);
}

/* ===== SOCIAL ICONS ===== */
.footer .social-icons {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin: 14px 0 16px;
    flex-wrap: wrap;
}

.footer .social-icon {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.05rem;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.footer .social-icon:hover {
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}

.footer .social-facebook { background: #1877f2; }
.footer .social-instagram { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); }
.footer .social-telegram { background: #0088cc; }
.footer .social-linkedin { background: #0a66c2; }

/* ===== FOOTER BOTTOM ===== */
.footer .footer-bottom {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border-light);
}

.footer .footer-bottom p {
    margin: 4px 0;
}

.footer .footer-bottom .developer-name {
    font-weight: 700;
    color: var(--text);
}

.footer .footer-bottom .copyright {
    color: var(--text-muted);
}

.footer .footer-bottom .email-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
}

.footer .footer-bottom .email-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.footer .footer-bottom .rights {
    font-size: 0.75rem;
    color: var(--text-muted);
    opacity: 0.6;
    margin-top: 6px;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 576px) {
    .footer {
        padding: 25px 16px 20px;
        font-size: 0.78rem;
    }
    
    .footer .footer-content h3 {
        font-size: 1.1rem;
    }
    
    .footer .social-icon {
        width: 36px;
        height: 36px;
        font-size: 0.9rem;
    }
}

        .alert { padding: 10px 16px; border-radius: 10px; margin-bottom: 12px; font-weight: 600; font-size: 0.85rem; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #d1fae5; color: #065f46; } .alert-error { background: #fee2e2; color: #991b1b; }

        @media (max-width: 1200px) { .sidebar-left { width: 250px; } .sidebar-right { width: 250px; } .products-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); } }
        @media (max-width: 992px) { .sidebar-left { display: none; } .sidebar-right { display: none; } .mobile-sidebar { display: block; } .menu-toggle-right { display: flex; } .menu-toggle-left { display: flex; } .content-area { padding: 15px; } .products-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; } .search-box { max-width: 300px; } }
        @media (max-width: 576px) { .header-inner { padding: 0 10px; gap: 6px; } .search-box { max-width: 180px; } .search-box input { font-size: 0.78rem; padding: 6px 10px; } .products-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; } .product-body { padding: 10px; } .product-name { font-size: 0.8rem; } .price { font-size: 0.9rem; } .btn-add { font-size: 0.72rem; padding: 8px; } .page-title { font-size: 1.1rem; } .logo { font-size: 1rem; } .logo span { display: none; } }
    </style>
</head>
<body>


<div style="background:#fef3c7;color:#92400e;text-align:center;padding:10px;font-size:0.85rem;font-weight:600;border-bottom:1px solid #fde68a;">
    ⚠️ موقع تجريبي | هذا المتجر غير فعال حالياً | لشراء الموقع أو الاستفسار: 
    <a href="mailto:benmirikram74@gmail.com" style="color:#2563eb;font-weight:700;">benmirikram74@gmail.com</a>
</div>

<header class="header">
    <div class="header-inner">
        <button class="menu-toggle-right" onclick="openRightSidebar()" title="التصنيفات"><i class="fas fa-th-large"></i></button>
        <a href="index.php" class="logo"><i class="fas fa-store"></i><span>متجري</span></a>
        <div class="search-box">
            <form action="index.php" method="GET" style="display:flex; width:100%;">
                <input type="text" name="search" placeholder="ابحث عن منتج..." value="<?php echo htmlspecialchars($search_query); ?>">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
        </div>
        
        <button class="menu-toggle-left" onclick="openLeftSidebar()" title="القائمة"><i class="fas fa-user"></i></button>
    </div>
</header>

<div class="overlay" id="overlay" onclick="closeAllSidebars()"></div>

<aside class="mobile-sidebar mobile-sidebar-right" id="mobileSidebarRight">
    <div class="sb-header"><h3>📂 التصنيفات</h3><button class="sb-close" onclick="closeAllSidebars()"><i class="fas fa-times"></i></button></div>
    <ul class="cat-list">
        <?php foreach ($categories as $cat): ?>
            <li><a href="?cat=<?php echo urlencode($cat['category']); ?>" class="<?php echo ($category == $cat['category']) ? 'active' : ''; ?>"><span class="cat-name"><span><?php echo $cat['icon']; ?></span> <span><?php echo htmlspecialchars($cat['category']); ?></span></span><span class="cat-count"><?php echo $cat['count']; ?></span></a></li>
        <?php endforeach; ?>
    </ul>
</aside>

<aside class="mobile-sidebar mobile-sidebar-left" id="mobileSidebarLeft">
    <div class="sb-header"><h3>👤 القائمة</h3><button class="sb-close" onclick="closeAllSidebars()"><i class="fas fa-times"></i></button></div>
    <?php if ($is_logged_in): ?>
        <div class="user-section"><div class="user-avatar"><i class="fas fa-user-circle"></i></div><div class="user-name"><?php echo htmlspecialchars($username); ?></div><div class="user-role"><?php echo $is_admin ? '👑 مشرف' : 'عميل'; ?></div></div>
    <?php endif; ?>
    <ul class="user-menu-list">
        <?php if ($is_logged_in): ?>
            <li><a href="profile.php"><i class="fas fa-user"></i> حسابي</a></li>
            <li><a href="orders.php"><i class="fas fa-box"></i> طلباتي</a></li>
            <li><a href="wishlist.php"><i class="fas fa-heart"></i> المفضلة <?php if($wishlist_count>0): ?>(<?php echo $wishlist_count; ?>)<?php endif; ?></a></li>
            <li><a href="cart.php"><i class="fas fa-shopping-cart"></i> سلة التسوق <?php if($cart_count>0): ?>(<?php echo $cart_count; ?>)<?php endif; ?></a></li>
            <?php if ($is_admin): ?><li class="divider"></li><li><a href="admin/index.php" class="text-warning"><i class="fas fa-crown"></i> لوحة التحكم</a></li><li><a href="admin/add_product.php" class="text-warning"><i class="fas fa-plus-circle"></i> إضافة منتج</a></li><?php endif; ?>
            <li class="divider"></li><li><a href="logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> تسجيل خروج</a></li>
        <?php else: ?>
            <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> تسجيل دخول</a></li>
            <li><a href="register.php"><i class="fas fa-user-plus"></i> إنشاء حساب</a></li>
            <li class="divider"></li><li><a href="wishlist.php"><i class="fas fa-heart"></i> المفضلة</a></li><li><a href="cart.php"><i class="fas fa-shopping-cart"></i> سلة التسوق</a></li>
        <?php endif; ?>
        <li class="divider"></li>
        <li><a href="index.php"><i class="fas fa-home"></i> الرئيسية</a></li>
        <li><a href="about.php"><i class="fas fa-info-circle"></i> من نحن</a></li>
        <li><a href="privacy.php"><i class="fas fa-lock"></i> سياسة الخصوصية</a></li>
        <li class="divider"></li>
        <li style="padding:10px 14px;font-size:0.8rem;color:#64748b;display:flex;align-items:center;gap:8px;"><i class="fas fa-envelope"></i> benmirikram74@gmail.com</li>
    </ul>
</aside>

<div class="main-container">
    <aside class="sidebar-left">
        <?php if ($is_logged_in): ?>
            <div class="user-section"><div class="user-avatar"><i class="fas fa-user-circle"></i></div><div class="user-name"><?php echo htmlspecialchars($username); ?></div><div class="user-role"><?php echo $is_admin ? '👑 مشرف' : '🛒 عميل'; ?></div></div>
        <?php endif; ?>
        <ul class="user-menu-list">
            <?php if ($is_logged_in): ?>
                <li><a href="profile.php"><i class="fas fa-user"></i> حسابي</a></li>
                <li><a href="orders.php"><i class="fas fa-box"></i> طلباتي</a></li>
                <li><a href="wishlist.php"><i class="fas fa-heart"></i> المفضلة <?php if($wishlist_count>0): ?><span style="font-size:0.7rem;background:var(--danger);color:white;padding:2px 6px;border-radius:10px;"><?php echo $wishlist_count; ?></span><?php endif; ?></a></li>
                <li><a href="cart.php"><i class="fas fa-shopping-cart"></i> سلة التسوق <?php if($cart_count>0): ?><span style="font-size:0.7rem;background:var(--danger);color:white;padding:2px 6px;border-radius:10px;"><?php echo $cart_count; ?></span><?php endif; ?></a></li>
                <?php if ($is_admin): ?><li class="divider"></li><li><a href="admin/index.php" class="text-warning"><i class="fas fa-crown"></i> لوحة التحكم</a></li><li><a href="admin/add_product.php" class="text-warning"><i class="fas fa-plus-circle"></i> إضافة منتج</a></li><?php endif; ?>
                <li class="divider"></li><li><a href="logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> تسجيل خروج</a></li>
            <?php else: ?>
                <li><a href="login.php"><i class="fas fa-sign-in-alt"></i> تسجيل دخول</a></li>
                <li><a href="register.php"><i class="fas fa-user-plus"></i> إنشاء حساب جديد</a></li>
                <li class="divider"></li><li><a href="wishlist.php"><i class="fas fa-heart"></i> المفضلة</a></li><li><a href="cart.php"><i class="fas fa-shopping-cart"></i> سلة التسوق</a></li>
            <?php endif; ?>
            <li class="divider"></li>
            <li><a href="index.php"><i class="fas fa-home"></i> الرئيسية</a></li>
            <li><a href="about.php"><i class="fas fa-info-circle"></i> من نحن</a></li>
            <li><a href="privacy.php"><i class="fas fa-lock"></i> سياسة الخصوصية</a></li>
            <li class="divider"></li>
            <li style="padding:10px 14px;font-size:0.8rem;color:#64748b;display:flex;align-items:center;gap:8px;"><i class="fas fa-envelope"></i> benmirikram74@gmail.com</li>
        </ul>
    </aside>

    <main class="content-area">
        <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

        <h1 class="page-title">
            <?php if ($category): ?>
                <?php $cat_icon = '📦'; foreach ($categories as $c) { if ($c['category'] == $category) { $cat_icon = $c['icon']; break; } } echo $cat_icon . ' ' . htmlspecialchars($category); ?>
            <?php elseif ($search_query): ?>
                🔍 نتائج البحث: "<?php echo htmlspecialchars($search_query); ?>"
            <?php else: ?>
                🏪 جميع المنتجات
            <?php endif; ?>
        </h1>

        <div class="toolbar">
            <span class="toolbar-text"><?php echo $total_products; ?> منتج</span>
            <form method="GET" style="display:flex; align-items:center; gap:8px;">
                <?php if ($category): ?><input type="hidden" name="cat" value="<?php echo htmlspecialchars($category); ?>"><?php endif; ?>
                <?php if ($search_query): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>"><?php endif; ?>
                <select name="sort" onchange="this.form.submit()">
                    <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>🆕 الأحدث</option>
                    <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>💰 السعر: من الأقل</option>
                    <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>💎 السعر: من الأعلى</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>🔤 الاسم: أ-ي</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>🔤 الاسم: ي-أ</option>
                </select>
            </form>
        </div>

        <?php if ($result && $result->num_rows > 0): ?>
            <div class="products-grid">
                <?php while ($product = $result->fetch_assoc()): ?>
                    <div class="product-card">
                        <div class="product-img">
                            <a href="product.php?id=<?php echo $product['id']; ?>">
                                <img src="<?php echo !empty($product['image']) ? $product['image'] : 'images/placeholder.png'; ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                     loading="lazy"
                                     onerror="this.src='images/placeholder.png';">
                            </a>
                            <button class="wishlist-btn <?php echo (isset($_SESSION['wishlist']) && in_array($product['id'], $_SESSION['wishlist'])) ? 'active' : ''; ?>"
                                    onclick="toggleWishlist(<?php echo $product['id']; ?>, this)" title="المفضلة">
                                <i class="fas fa-heart"></i>
                            </button>
                        </div>
                        <div class="product-body">
                            <div class="product-cat"><?php echo htmlspecialchars($product['category']); ?></div>
                            <h3 class="product-name"><a href="product.php?id=<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></a></h3>
                            <div class="price-row">
                                <span class="price"><?php echo number_format($product['price'], 2); ?> ريال</span>
                            </div>
                            <button class="btn-add" onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', <?php echo $product['price']; ?>)">
                                <i class="fas fa-cart-plus"></i> أضف للسلة
                            </button>
                            <?php if ($is_admin): ?><a href="admin/edit_product.php?id=<?php echo $product['id']; ?>" class="btn-edit-admin"><i class="fas fa-edit"></i> تعديل (مشرف)</a><?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php $query_params = $_GET; unset($query_params['page']); $base_url = '?' . http_build_query($query_params); if ($base_url != '?') $base_url .= '&'; ?>
                    <?php if ($page > 1): ?><a href="<?php echo $base_url; ?>page=<?php echo $page - 1; ?>"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?><?php if ($i == $page): ?><span class="active"><?php echo $i; ?></span><?php else: ?><a href="<?php echo $base_url; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a><?php endif; ?><?php endfor; ?>
                    <?php if ($page < $total_pages): ?><a href="<?php echo $base_url; ?>page=<?php echo $page + 1; ?>"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-box-open"></i><h2>لا توجد منتجات</h2><p>لم يتم العثور على منتجات</p><a href="index.php" class="btn btn-primary" style="margin-top:12px;"><i class="fas fa-home"></i> عرض الكل</a></div>
        <?php endif; ?>
    </main>

    <aside class="sidebar-right">
        <h3 class="sidebar-title">📂 التصنيفات</h3>
        <ul class="cat-list">
            <?php foreach ($categories as $cat): ?>
                <li><a href="?cat=<?php echo urlencode($cat['category']); ?>" class="<?php echo ($category == $cat['category']) ? 'active' : ''; ?>"><span class="cat-name"><span><?php echo $cat['icon']; ?></span> <span><?php echo htmlspecialchars($cat['category']); ?></span></span><span class="cat-count"><?php echo $cat['count']; ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </aside>
</div>

<!-- ===== FOOTER ===== -->
<footer class="footer">
    <div class="footer-content">
        <!-- اسم الموقع -->
        <h3>Shop<span>DZ</span></h3>
        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 6px;">متجرك الإلكتروني في الجزائر</div>
        
        <!-- روابط التواصل الاجتماعي -->
        <div class="social-icons">
            <a href="https://www.facebook.com/share/1BHHFwtAsH/" target="_blank" class="social-icon social-facebook" title="فيسبوك">
                <i class="fab fa-facebook-f"></i>
            </a>
            <a href="https://www.instagram.com/ikramben34?igsh=MXRoOHNkejQ4cTBjdw==" target="_blank" class="social-icon social-instagram" title="إنستغرام">
                <i class="fab fa-instagram"></i>
            </a>
            <a href="https://t.me/Digital_Library22" target="_blank" class="social-icon social-telegram" title="تلغرام">
                <i class="fab fa-telegram-plane"></i>
            </a>
            <a href="https://www.linkedin.com/in/ikram-ben-mir-41037a420" target="_blank" class="social-icon social-linkedin" title="لينكد إن">
                <i class="fab fa-linkedin-in"></i>
            </a>
        </div>
        
        <div class="footer-bottom">
            <!-- المطور -->
            <p style="font-size: 0.9rem; margin-bottom: 4px;">
                <span style="font-weight: 700; color: var(--text);">Developed by BEN MIR IKRAM</span>
                <span style="color: var(--text-muted);">© <?php echo date('Y'); ?></span>
            </p>
            
            <!-- البريد الإلكتروني -->
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 4px 0;">
                <i class="fas fa-envelope" style="margin-left: 5px;"></i>
                <a href="mailto:benmirikram74@gmail.com" style="color: var(--accent); text-decoration: none;">benmirikram74@gmail.com</a>
            </p>
            
            <!-- حقوق النشر -->
            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 4px 0 0;">
                جميع الحقوق محفوظة
            </p>
        </div>
    </div>
</footer>

<script>
    function openRightSidebar() { document.getElementById('mobileSidebarRight').classList.add('open'); document.getElementById('overlay').style.display = 'block'; }
    function openLeftSidebar() { document.getElementById('mobileSidebarLeft').classList.add('open'); document.getElementById('overlay').style.display = 'block'; }
    function closeAllSidebars() { document.getElementById('mobileSidebarRight').classList.remove('open'); document.getElementById('mobileSidebarLeft').classList.remove('open'); document.getElementById('overlay').style.display = 'none'; }
    function addToCart(productId, productName, price) {
        fetch('add_to_cart.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'product_id=' + productId + '&quantity=1' })
        .then(r => r.json()).then(d => { if (d.success) { alert('✅ تمت إضافة "' + productName + '" إلى السلة!'); location.reload(); } else { alert('❌ ' + (d.message || 'خطأ')); } }).catch(() => alert('❌ خطأ في الاتصال'));
    }
    function toggleWishlist(productId, btn) {
        fetch('toggle_wishlist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'product_id=' + productId })
        .then(r => r.json()).then(d => { if (d.success) { btn.classList.toggle('active'); location.reload(); } else { alert('❌ ' + (d.message || 'يجب تسجيل الدخول')); <?php if(!$is_logged_in): ?>window.location.href='login.php';<?php endif; ?> } });
    }
</script>
</body>
</html>
