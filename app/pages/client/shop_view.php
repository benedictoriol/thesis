<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';

require_role(['client']);

$pageTitle = 'Shop Profile';

function get_table_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $exception) {
        return [];
    }
}

function find_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function format_day_label($day): string
{
    $dayNames = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    if (is_numeric($day)) {
        $index = (int) $day;
        return $dayNames[$index] ?? 'Day ' . $day;
    }

    $label = trim((string) $day);
    if ($label === '') {
        return 'Day';
    }

    return ucwords(str_replace(['_', '-'], ' ', $label));
}

function format_time_label(?string $time): string
{
    if (!$time) {
        return '—';
    }

    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return $time;
    }

    return date('g:i A', $timestamp);
}

function day_sort_key($day): int
{
    $map = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    if (is_numeric($day)) {
        $value = (int) $day;
        return $value === 0 ? 7 : $value;
    }

    $normalized = strtolower(trim((string) $day));
    $normalized = str_replace(['_', '-'], ' ', $normalized);
    $normalized = explode(' ', $normalized)[0];

    return $map[$normalized] ?? 99;
}

$shopId = (int) ($_GET['id'] ?? 0);
$errors = [];
$shop = null;
$hours = [];
$availability = [];
$products = [];
$portfolioItems = [];
$portfolioImages = [];
$reviewSummary = [
    'avg_rating' => 0,
    'review_count' => 0,
];
$recentReviews = [];
$hiringPosts = [];

if ($shopId <= 0) {
    $errors[] = 'Invalid shop selection.';
} else {
    try {
        $shopStmt = db()->prepare(
            'SELECT id, name, description, address_text, logo_path, cover_path
             FROM shops
             WHERE id = :shop_id
             LIMIT 1'
        );
        $shopStmt->execute(['shop_id' => $shopId]);
        $shop = $shopStmt->fetch();

        if (!$shop) {
            $errors[] = 'Shop not found.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load shop details right now.';
    }
}

if ($shop) {
    $availabilityColumns = get_table_columns(db(), 'shop_availability');
    if ($availabilityColumns) {
        try {
            $availabilityStmt = db()->prepare(
                'SELECT * FROM shop_availability WHERE shop_id = :shop_id LIMIT 1'
            );
            $availabilityStmt->execute(['shop_id' => $shopId]);
            $availability = $availabilityStmt->fetch() ?: [];
        } catch (PDOException $exception) {
            $availability = [];
        }
    }

    $hoursColumns = get_table_columns(db(), 'shop_hours');
    if ($hoursColumns) {
        try {
            $hoursStmt = db()->prepare(
                'SELECT day_of_week, open_time, close_time, is_closed
                 FROM shop_hours
                 WHERE shop_id = :shop_id'
            );
            $hoursStmt->execute(['shop_id' => $shopId]);
            $hours = $hoursStmt->fetchAll();
        } catch (PDOException $exception) {
            $hours = [];
        }

        if ($hours) {
            usort($hours, static function (array $a, array $b): int {
                $keyA = day_sort_key($a['day_of_week'] ?? '');
                $keyB = day_sort_key($b['day_of_week'] ?? '');
                if ($keyA === $keyB) {
                    return 0;
                }
                return $keyA < $keyB ? -1 : 1;
            });
        }
    }

    $productColumns = get_table_columns(db(), 'products');
    if ($productColumns) {
        $hasStatus = in_array('status', $productColumns, true);
        $hasCreatedAt = in_array('created_at', $productColumns, true);

        $conditions = ['p.shop_id = :shop_id'];
        if ($hasStatus) {
            $conditions[] = "p.status = 'active'";
        }

        $orderBy = $hasCreatedAt ? 'p.created_at DESC' : 'p.id DESC';

        try {
            $sql = "SELECT p.id, p.name, p.description, p.base_price,
                    (
                        SELECT pi.image_path
                        FROM product_images pi
                        WHERE pi.product_id = p.id
                        ORDER BY pi.sort_order ASC, pi.id ASC
                        LIMIT 1
                    ) AS image_path
                FROM products p
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY $orderBy
                LIMIT 12";
            $productStmt = db()->prepare($sql);
            $productStmt->execute(['shop_id' => $shopId]);
            $products = $productStmt->fetchAll();
        } catch (PDOException $exception) {
            $products = [];
        }
    }

    $portfolioColumns = get_table_columns(db(), 'shop_portfolio');
    if ($portfolioColumns) {
        $hasStatus = in_array('status', $portfolioColumns, true);
        $hasCreatedAt = in_array('created_at', $portfolioColumns, true);
        $conditions = ['sp.shop_id = :shop_id'];
        if ($hasStatus) {
            $conditions[] = "sp.status = 'active'";
        }

        $orderBy = $hasCreatedAt ? 'sp.created_at DESC' : 'sp.id DESC';

        try {
            $sql = "SELECT sp.id, sp.title, sp.description, sp.category_id";
            if ($hasStatus) {
                $sql .= ", sp.status";
            }
            if ($hasCreatedAt) {
                $sql .= ", sp.created_at";
            }
            $sql .= ", c.name AS category_name
                FROM shop_portfolio sp
                LEFT JOIN categories c ON c.id = sp.category_id
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY $orderBy
                LIMIT 12";
            $portfolioStmt = db()->prepare($sql);
            $portfolioStmt->execute(['shop_id' => $shopId]);
            $portfolioItems = $portfolioStmt->fetchAll();
        } catch (PDOException $exception) {
            $portfolioItems = [];
        }
    }

    if ($portfolioItems && get_table_columns(db(), 'shop_portfolio_images')) {
        try {
            $portfolioIds = array_map(static fn(array $item) => (int) $item['id'], $portfolioItems);
            $placeholders = implode(',', array_fill(0, count($portfolioIds), '?'));
            $imageStmt = db()->prepare(
                "SELECT portfolio_id, image_path
                 FROM shop_portfolio_images
                 WHERE portfolio_id IN ($placeholders)"
            );
            $imageStmt->execute($portfolioIds);
            foreach ($imageStmt->fetchAll() as $row) {
                $portfolioImages[(int) $row['portfolio_id']][] = $row['image_path'];
            }
        } catch (PDOException $exception) {
            $portfolioImages = [];
        }
    }

    if (get_table_columns(db(), 'reviews')) {
        try {
            $summaryStmt = db()->prepare(
                'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
                 FROM reviews
                 WHERE shop_id = :shop_id'
            );
            $summaryStmt->execute(['shop_id' => $shopId]);
            $summary = $summaryStmt->fetch();
            if ($summary) {
                $reviewSummary = [
                    'avg_rating' => (float) ($summary['avg_rating'] ?? 0),
                    'review_count' => (int) ($summary['review_count'] ?? 0),
                ];
            }
        } catch (PDOException $exception) {
            $reviewSummary = [
                'avg_rating' => 0,
                'review_count' => 0,
            ];
        }

        try {
            $reviewStmt = db()->prepare(
                'SELECT r.rating, r.comment, r.created_at, u.fullname AS client_name
                 FROM reviews r
                 JOIN users u ON u.id = r.client_user_id
                 WHERE r.shop_id = :shop_id
                 ORDER BY r.created_at DESC
                 LIMIT 3'
            );
            $reviewStmt->execute(['shop_id' => $shopId]);
            $recentReviews = $reviewStmt->fetchAll();
        } catch (PDOException $exception) {
            $recentReviews = [];
        }
    }

    $hiringColumns = get_table_columns(db(), 'shop_hiring');
    if ($hiringColumns) {
        $titleColumn = find_column($hiringColumns, ['title', 'position', 'role_name']);
        $descriptionColumn = find_column($hiringColumns, ['description', 'details']);
        $statusColumn = find_column($hiringColumns, ['status']);
        $createdColumn = find_column($hiringColumns, ['created_at', 'posted_at']);

        try {
            $select = ['id', 'shop_id'];
            if ($titleColumn) {
                $select[] = "$titleColumn AS title";
            }
            if ($descriptionColumn) {
                $select[] = "$descriptionColumn AS description";
            }
            if ($statusColumn) {
                $select[] = "$statusColumn AS status";
            }
            if ($createdColumn) {
                $select[] = "$createdColumn AS created_at";
            }
            $sql = "SELECT " . implode(', ', $select) . " FROM shop_hiring WHERE shop_id = :shop_id";
            if ($statusColumn) {
                $sql .= " AND $statusColumn = 'open'";
            }
            if ($createdColumn) {
                $sql .= " ORDER BY $createdColumn DESC";
            }
            $sql .= ' LIMIT 5';

            $hiringStmt = db()->prepare($sql);
            $hiringStmt->execute(['shop_id' => $shopId]);
            $hiringPosts = $hiringStmt->fetchAll();
        } catch (PDOException $exception) {
            $hiringPosts = [];
        }
    }
}

require __DIR__ . '/../../includes/header.php';
?>
<h1 class="h4 mb-3">Shop Profile</h1>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if ($shop): ?>
    <?php
    $shopName = $shop['name'] ?? 'Shop';
    $shopAddress = $shop['address_text'] ?? '';
    $messageLink = '/messages?action=start&shop_id=' . (int) $shop['id'];
    $avgRating = $reviewSummary['avg_rating'] ?? 0;
    $reviewCount = $reviewSummary['review_count'] ?? 0;
    $productSearchLink = '/client/search?type=products&query=' . rawurlencode($shopName);
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row gap-4">
                <div class="flex-shrink-0">
                    <?php if (!empty($shop['logo_path'])): ?>
                        <img src="<?= htmlspecialchars($shop['logo_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?>"
                             class="rounded border"
                             style="width: 96px; height: 96px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-secondary text-white d-flex align-items-center justify-content-center rounded"
                             style="width: 96px; height: 96px;">
                            <span class="fw-semibold"><?= htmlspecialchars(mb_substr($shopName, 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <h2 class="h5 mb-1"><?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($shopAddress): ?>
                                <div class="text-muted small mb-2"><?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($shop['description'])): ?>
                                <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($shop['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-md-end">
                            <a class="btn btn-outline-dark" href="<?= $messageLink ?>">Message shop</a>
                            <div class="mt-2 text-muted small">Average rating: <?= number_format($avgRating, 1) ?> (<?= (int) $reviewCount ?> reviews)</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (!empty($shop['cover_path'])): ?>
                <div class="mt-4">
                    <img src="<?= htmlspecialchars($shop['cover_path'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?> cover"
                         class="img-fluid rounded border">
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hiringPosts): ?>
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                    Overview
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="hiring-tab" data-bs-toggle="tab" data-bs-target="#hiring" type="button" role="tab">
                    Hiring
                </button>
            </li>
        </ul>
    <?php endif; ?>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="overview" role="tabpanel">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">About & Address</h3>
                            <?php if (!empty($shop['description'])): ?>
                                <p class="text-muted mb-3"><?= nl2br(htmlspecialchars($shop['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php else: ?>
                                <p class="text-muted mb-3">This shop has not added a detailed description yet.</p>
                            <?php endif; ?>
                            <?php if ($shopAddress): ?>
                                <div class="fw-semibold">Location</div>
                                <div class="text-muted"><?= htmlspecialchars($shopAddress, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <div class="text-muted">Address details are coming soon.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Service Availability</h3>
                            <?php
                            $availabilityFlags = [
                                'accepting_orders' => 'Accepting orders',
                                'accepting_custom' => 'Custom requests',
                                'accepting_quotes' => 'Quotes',
                                'accepting_rush' => 'Rush service',
                            ];
                            ?>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach ($availabilityFlags as $flag => $label): ?>
                                    <?php if (array_key_exists($flag, $availability)): ?>
                                        <?php $isActive = (bool) $availability[$flag]; ?>
                                        <span class="badge rounded-pill <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-muted small">
                                <?php if (array_key_exists('daily_capacity', $availability) && $availability['daily_capacity'] !== null): ?>
                                    <div>Daily capacity: <?= htmlspecialchars((string) $availability['daily_capacity'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($availability['queue_note'])): ?>
                                    <div>Queue note: <?= htmlspecialchars($availability['queue_note'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($availability['updated_at'])): ?>
                                    <div>Last updated: <?= htmlspecialchars($availability['updated_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!$availability): ?>
                                    <div>No availability details provided.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Operating Hours</h3>
                            <?php if ($hours): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Day</th>
                                                <th scope="col">Hours</th>
                                                <th scope="col">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($hours as $hour): ?>
                                                <?php
                                                $isClosed = !empty($hour['is_closed']);
                                                $openTime = $hour['open_time'] ?? null;
                                                $closeTime = $hour['close_time'] ?? null;
                                                $timeRange = $isClosed ? 'Closed' : format_time_label($openTime) . ' - ' . format_time_label($closeTime);
                                                ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(format_day_label($hour['day_of_week'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars($timeRange, ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <?php if ($isClosed): ?>
                                                            <span class="badge bg-secondary">Closed</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Open</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Operating hours are not available yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h3 class="h6 mb-0">Service Catalog</h3>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($productSearchLink, ENT_QUOTES, 'UTF-8') ?>">See all</a>
                            </div>
                            <?php if ($products): ?>
                                <div class="row g-3">
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        $price = (float) ($product['base_price'] ?? 0);
                                        $priceLabel = $price > 0 ? '₱' . number_format($price, 2) : 'Quote required';
                                        ?>
                                        <div class="col-12 col-md-6 col-lg-4">
                                            <div class="card h-100 border-0 shadow-sm">
                                                <?php if (!empty($product['image_path'])): ?>
                                                    <img src="<?= htmlspecialchars($product['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                                                         class="card-img-top"
                                                         alt="<?= htmlspecialchars($product['name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?>"
                                                         style="height: 160px; object-fit: cover;">
                                                <?php endif; ?>
                                                <div class="card-body">
                                                    <h4 class="h6 mb-1"><?= htmlspecialchars($product['name'] ?? 'Product', ENT_QUOTES, 'UTF-8') ?></h4>
                                                    <?php if (!empty($product['description'])): ?>
                                                        <p class="text-muted small mb-2"><?= htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                                    <?php endif; ?>
                                                    <div class="fw-semibold mb-2"><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                                    <a class="btn btn-sm btn-outline-dark" href="/client/product_view?id=<?= (int) $product['id'] ?>">View product</a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No services have been published yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Portfolio Gallery</h3>
                            <?php if ($portfolioItems): ?>
                                <div class="row g-3">
                                    <?php foreach ($portfolioItems as $item): ?>
                                        <?php
                                        $images = $portfolioImages[(int) $item['id']] ?? [];
                                        $coverImage = $images[0] ?? '';
                                        ?>
                                        <div class="col-12 col-md-6 col-lg-4">
                                            <div class="card h-100 border-0 shadow-sm">
                                                <?php if ($coverImage): ?>
                                                    <img src="<?= htmlspecialchars($coverImage, ENT_QUOTES, 'UTF-8') ?>"
                                                         class="card-img-top"
                                                         alt="<?= htmlspecialchars($item['title'] ?? 'Portfolio item', ENT_QUOTES, 'UTF-8') ?>"
                                                         style="height: 160px; object-fit: cover;">
                                                <?php endif; ?>
                                                <div class="card-body">
                                                    <h4 class="h6 mb-1"><?= htmlspecialchars($item['title'] ?? 'Portfolio item', ENT_QUOTES, 'UTF-8') ?></h4>
                                                    <?php if (!empty($item['category_name'])): ?>
                                                        <span class="badge bg-light text-dark border mb-2">
                                                            <?= htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <p class="text-muted small mb-0"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Portfolio items will appear here when available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h3 class="h6">Reviews Summary</h3>
                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3">
                                <div>
                                    <div class="display-6 mb-0"><?= number_format($avgRating, 1) ?></div>
                                    <div class="text-muted">Based on <?= (int) $reviewCount ?> reviews</div>
                                </div>
                                <div class="mt-3 mt-md-0">
                                    <span class="badge bg-success">Top rating</span>
                                </div>
                            </div>
                            <?php if ($recentReviews): ?>
                                <div class="list-group">
                                    <?php foreach ($recentReviews as $review): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between">
                                                <div class="fw-semibold"><?= htmlspecialchars($review['client_name'] ?? 'Client', ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($review['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                            <div class="text-warning">Rating: <?= (int) ($review['rating'] ?? 0) ?>/5</div>
                                            <?php if (!empty($review['comment'])): ?>
                                                <p class="text-muted mb-0"><?= htmlspecialchars($review['comment'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No reviews have been shared yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($hiringPosts): ?>
            <div class="tab-pane fade" id="hiring" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h3 class="h6">Hiring</h3>
                        <div class="list-group">
                            <?php foreach ($hiringPosts as $post): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div class="fw-semibold"><?= htmlspecialchars($post['title'] ?? 'Open role', ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($post['status'])): ?>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($post['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($post['created_at'])): ?>
                                        <div class="text-muted small mb-2">Posted <?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($post['description'])): ?>
                                        <p class="text-muted mb-0"><?= htmlspecialchars($post['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>