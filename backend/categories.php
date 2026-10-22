<?php
/**
 * Admin Panel: Home Screen Categories & Shelf Ordering Manager
 * Allows admins to create custom home screen categories, assign filters (type/genre/sort),
 * and dynamically re-order their placing on the mobile app home screen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

if (!Database::isInstalled()) {
    header('Location: install/install.php');
    exit;
}

Auth::requireAuth();

$pdo = Database::getInstance();
ensureDatabaseTables($pdo);
$currentUser = Auth::user();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$error = '';
$success = '';

// Preset available genres
$allGenres = ['Action', 'Thriller', 'Drama', 'Comedy', 'Sci-Fi', 'Romance', 'Horror', 'Adventure', 'Animation', 'Crime', 'Biography', 'Sports', 'News', 'HD', 'World'];

// -------------------------------------------------------------
// POST ACTIONS: ADD, EDIT, DELETE, TOGGLE, REORDER
// -------------------------------------------------------------

// 1. ADD NEW CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_category') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $contentType = trim($_POST['content_type'] ?? 'movies');
        $filterGenre = trim($_POST['filter_genre'] ?? '');
        $sortBy = trim($_POST['sort_by'] ?? 'latest');
        $displayOrder = (int)($_POST['display_order'] ?? 0);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if (empty($title)) {
            $error = 'Category Title is required.';
        } else {
            if (empty($slug)) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
            }

            // Auto-assign order to the end if 0
            if ($displayOrder <= 0) {
                $maxOrder = $pdo->query("SELECT MAX(display_order) FROM home_categories")->fetchColumn();
                $displayOrder = ((int)$maxOrder) + 1;
            }

            try {
                $ins = $pdo->prepare("
                    INSERT INTO home_categories (title, slug, subtitle, content_type, filter_genre, sort_by, display_order, is_enabled)
                    VALUES (:title, :slug, :subtitle, :type, :genre, :sort, :ord, :en)
                ");
                $ins->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'subtitle' => $subtitle,
                    'type' => $contentType,
                    'genre' => $filterGenre,
                    'sort' => $sortBy,
                    'ord' => $displayOrder,
                    'en' => $isEnabled,
                ]);

                setFlash('success', "Category '{$title}' successfully created and placed at rank #{$displayOrder}.");
                header('Location: categories.php');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to create category: ' . $e->getMessage();
            }
        }
    }
}

// 2. EDIT CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_category') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '');
        $contentType = trim($_POST['content_type'] ?? 'movies');
        $filterGenre = trim($_POST['filter_genre'] ?? '');
        $sortBy = trim($_POST['sort_by'] ?? 'latest');
        $displayOrder = (int)($_POST['display_order'] ?? 1);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if ($id <= 0 || empty($title)) {
            $error = 'Valid ID and Title are required.';
        } else {
            try {
                $up = $pdo->prepare("
                    UPDATE home_categories
                    SET title = :title, slug = :slug, subtitle = :subtitle, content_type = :type,
                        filter_genre = :genre, sort_by = :sort, display_order = :ord, is_enabled = :en
                    WHERE id = :id
                ");
                $up->execute([
                    'title' => $title,
                    'slug' => $slug,
                    'subtitle' => $subtitle,
                    'type' => $contentType,
                    'genre' => $filterGenre,
                    'sort' => $sortBy,
                    'ord' => $displayOrder,
                    'en' => $isEnabled,
                    'id' => $id,
                ]);

                setFlash('success', "Category '{$title}' updated successfully.");
                header('Location: categories.php');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to update category: ' . $e->getMessage();
            }
        }
    }
}

// 3. MOVE UP (Placing reorder)
if ($action === 'move_up') {
    $id = (int)($_GET['id'] ?? 0);
    $csrf = $_GET['csrf'] ?? '';
    if (verifyCsrfToken($csrf) && $id > 0) {
        $cur = $pdo->prepare("SELECT id, display_order FROM home_categories WHERE id = :id");
        $cur->execute(['id' => $id]);
        $row = $cur->fetch();
        if ($row) {
            $curOrder = (int)$row['display_order'];
            // Find item with closest lower order
            $prev = $pdo->prepare("SELECT id, display_order FROM home_categories WHERE display_order < :ord ORDER BY display_order DESC LIMIT 1");
            $prev->execute(['ord' => $curOrder]);
            $prevRow = $prev->fetch();
            if ($prevRow) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE home_categories SET display_order = :ord WHERE id = :id")->execute(['ord' => $prevRow['display_order'], 'id' => $id]);
                $pdo->prepare("UPDATE home_categories SET display_order = :ord WHERE id = :id")->execute(['ord' => $curOrder, 'id' => $prevRow['id']]);
                $pdo->commit();
                setFlash('success', "Category moved up.");
            }
        }
    }
    header('Location: categories.php');
    exit;
}

// 4. MOVE DOWN (Placing reorder)
if ($action === 'move_down') {
    $id = (int)($_GET['id'] ?? 0);
    $csrf = $_GET['csrf'] ?? '';
    if (verifyCsrfToken($csrf) && $id > 0) {
        $cur = $pdo->prepare("SELECT id, display_order FROM home_categories WHERE id = :id");
        $cur->execute(['id' => $id]);
        $row = $cur->fetch();
        if ($row) {
            $curOrder = (int)$row['display_order'];
            // Find item with closest higher order
            $next = $pdo->prepare("SELECT id, display_order FROM home_categories WHERE display_order > :ord ORDER BY display_order ASC LIMIT 1");
            $next->execute(['ord' => $curOrder]);
            $nextRow = $next->fetch();
            if ($nextRow) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE home_categories SET display_order = :ord WHERE id = :id")->execute(['ord' => $nextRow['display_order'], 'id' => $id]);
                $pdo->prepare("UPDATE home_categories SET display_order = :ord WHERE id = :id")->execute(['ord' => $curOrder, 'id' => $nextRow['id']]);
                $pdo->commit();
                setFlash('success', "Category moved down.");
            }
        }
    }
    header('Location: categories.php');
    exit;
}

// 5. TOGGLE STATUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_status') {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $id = (int)($_POST['id'] ?? 0);
        $en = !empty($_POST['is_enabled']) ? 1 : 0;
        $pdo->prepare("UPDATE home_categories SET is_enabled = :en WHERE id = :id")->execute(['en' => $en, 'id' => $id]);
        setFlash('success', "Category visibility updated.");
    }
    header('Location: categories.php');
    exit;
}

// 6. DELETE CATEGORY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_category') {
    if (verifyCsrfToken($_POST['csrf'] ?? '')) {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM home_categories WHERE id = :id")->execute(['id' => $id]);
        setFlash('success', "Category removed from home screen.");
    }
    header('Location: categories.php');
    exit;
}

// Fetch all categories sorted by display_order ASC
$catStmt = $pdo->query("SELECT * FROM home_categories ORDER BY display_order ASC, id ASC");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCats = count($categories);
$enabledCats = count(array_filter($categories, fn($c) => $c['is_enabled'] == 1));

$csrfToken = generateCsrfToken();
$pageTitle = 'Home Categories & Shelf Ordering';
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Home Screen Categories & Placing</h1>
        <p class="page-subtitle">Add new categories, customize filtering rules, and configure the display placing of shelves in the mobile app.</p>
    </div>
    <div class="topbar-actions">
        <button type="button" class="btn btn-primary" onclick="openAddCategoryModal()">
            <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add New Category
        </button>
        <a href="index.php" class="btn btn-secondary">
            &larr; Dashboard
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Top Metrics -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div>
            <div class="stat-label">Configured Shelves</div>
            <div class="stat-value"><?= number_format($totalCats) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Total Categories</span>
        </div>
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Active on Home</div>
            <div class="stat-value" style="color: #34d399;"><?= number_format($enabledCats) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Visible in App Feed</span>
        </div>
        <div class="stat-icon emerald">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Placing Order</div>
            <div class="stat-value" style="color: #38bdf8;">Custom</div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Rank #1 to #<?= $totalCats ?></span>
        </div>
        <div class="stat-icon purple">
            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: start;">
    
    <!-- Left Column: Category Re-Ordering Table -->
    <div class="content-card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Home Screen Category Placing</h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;">
                    Categories at the top appear first in the mobile app category chips and home shelves. Use <strong>▲</strong> and <strong>▼</strong> to adjust order.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 90px; text-align: center;">Placing</th>
                        <th>Category Details</th>
                        <th>Filter Type</th>
                        <th>Sorting</th>
                        <th style="width: 100px;">Status</th>
                        <th style="text-align: right; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                                No categories configured. Click "+ Add New Category" above to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $idx => $cat): ?>
                            <tr>
                                <td style="text-align: center;">
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 2px;">
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <!-- Move Up Button -->
                                            <?php if ($idx > 0): ?>
                                                <a href="categories.php?action=move_up&id=<?= $cat['id'] ?>&csrf=<?= $csrfToken ?>" class="btn-icon" style="width: 24px; height: 24px; font-size: 0.75rem;" title="Move Up in placing">
                                                    ▲
                                                </a>
                                            <?php else: ?>
                                                <span style="width: 24px; height: 24px; display: inline-block;"></span>
                                            <?php endif; ?>

                                            <span style="font-size: 0.95rem; font-weight: 800; color: #fff; background: rgba(255,255,255,0.06); padding: 2px 8px; border-radius: 6px; border: 1px solid var(--border-color);">
                                                #<?= $cat['display_order'] ?>
                                            </span>

                                            <!-- Move Down Button -->
                                            <?php if ($idx < count($categories) - 1): ?>
                                                <a href="categories.php?action=move_down&id=<?= $cat['id'] ?>&csrf=<?= $csrfToken ?>" class="btn-icon" style="width: 24px; height: 24px; font-size: 0.75rem;" title="Move Down in placing">
                                                    ▼
                                                </a>
                                            <?php else: ?>
                                                <span style="width: 24px; height: 24px; display: inline-block;"></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <div style="font-weight: 700; color: #fff; font-size: 0.95rem;">
                                            <?= htmlspecialchars($cat['title']) ?>
                                        </div>
                                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 2px;">
                                            <?= !empty($cat['subtitle']) ? htmlspecialchars($cat['subtitle']) : '<span style="opacity: 0.5;">No subtitle</span>' ?>
                                        </div>
                                        <span style="font-size: 0.72rem; color: #64748b; font-family: monospace;">slug: <?= htmlspecialchars($cat['slug']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $badgeColor = match($cat['content_type']) {
                                            'movies' => 'rgba(229, 9, 20, 0.15); color: #f87171; border: 1px solid rgba(229, 9, 20, 0.3)',
                                            'bollywood' => 'rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3)',
                                            'hollywood' => 'rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3)',
                                            'series' => 'rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3)',
                                            'liveTv' => 'rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3)',
                                            default => 'rgba(255, 255, 255, 0.08); color: #cbd5e1; border: 1px solid var(--border-color)',
                                        };
                                    ?>
                                    <span style="display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: <?= $badgeColor ?>;">
                                        <?= htmlspecialchars(ucfirst($cat['content_type'])) ?>
                                    </span>
                                    <?php if (!empty($cat['filter_genre'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 3px;">
                                            Genre: <strong><?= htmlspecialchars($cat['filter_genre']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: #cbd5e1; font-weight: 600;">
                                        <?= match($cat['sort_by']) {
                                            'latest' => '★ Latest First',
                                            'rating' => '★ Highest Rating',
                                            'views' => '★ Most Views',
                                            'alphabetical' => 'A-Z Alphabetical',
                                            default => 'Latest First',
                                        } ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="categories.php" style="display: inline;">
                                        <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                        <input type="hidden" name="is_enabled" value="<?= $cat['is_enabled'] ? 0 : 1 ?>">
                                        <?php if ($cat['is_enabled']): ?>
                                            <button type="submit" class="badge-status active" style="border: none; cursor: pointer;" title="Click to disable">
                                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399;"></span>
                                                Active
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="badge-status suspended" style="border: none; cursor: pointer;" title="Click to enable">
                                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #f87171;"></span>
                                                Disabled
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                        <!-- Edit Button -->
                                        <button type="button" class="btn-icon" title="Edit Category & Placing" onclick="openEditCategoryModal(<?= htmlspecialchars(json_encode($cat)) ?>)">
                                            <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                        </button>

                                        <!-- Delete Button -->
                                        <form method="POST" action="categories.php" style="display: inline;">
                                            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn-icon delete" title="Delete category" onclick="return confirm('Remove category <?= htmlspecialchars(addslashes($cat['title'])) ?> from home screen?')">
                                                <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column: Interactive App Home Screen Placing Preview -->
    <div>
        <div class="content-card" style="padding: 1.25rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <h3 class="card-title" style="font-size: 1.05rem;">Mobile App Preview</h3>
                <span style="font-size: 0.72rem; color: #38bdf8; background: rgba(56, 189, 248, 0.15); padding: 2px 6px; border-radius: 4px; font-weight: 700;">Live Simulation</span>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1rem;">
                This represents the order of category chips and vertical shelves seen by users on the home screen.
            </p>

            <div style="background: #000; border-radius: 20px; border: 2px solid rgba(255,255,255,0.15); padding: 1rem 0.85rem; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <!-- Simulated App Bar -->
                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 0.75rem;">
                    <div style="font-weight: 900; font-size: 0.9rem; color: #fff;">
                        PIKA<span style="color: var(--accent-red);">SHOW</span>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <span style="width: 14px; height: 14px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-block;"></span>
                        <span style="width: 14px; height: 14px; border-radius: 50%; background: var(--accent-red); display: inline-block;"></span>
                    </div>
                </div>

                <!-- Simulated Category Chips Bar -->
                <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); margin-bottom: 4px; text-transform: uppercase;">Top Category Chips:</div>
                <div style="display: flex; gap: 4px; overflow-x: auto; padding-bottom: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 0.75rem;">
                    <span style="background: var(--accent-red); color: #fff; font-size: 0.68rem; font-weight: 700; padding: 3px 8px; border-radius: 12px; white-space: nowrap;">All</span>
                    <?php foreach ($categories as $c): ?>
                        <?php if ($c['is_enabled']): ?>
                            <span style="background: rgba(255,255,255,0.08); color: #cbd5e1; font-size: 0.68rem; font-weight: 600; padding: 3px 8px; border-radius: 12px; white-space: nowrap; border: 1px solid rgba(255,255,255,0.1);">
                                <?= htmlspecialchars($c['title']) ?>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Simulated Shelves in configured placing -->
                <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); margin-bottom: 6px; text-transform: uppercase;">Home Shelves Placing:</div>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($categories as $idx => $c): ?>
                        <?php if ($c['is_enabled']): ?>
                            <div style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                    <div style="font-weight: 700; font-size: 0.75rem; color: #fff;">
                                        <span style="color: var(--accent-red); margin-right: 4px;">#<?= $idx + 1 ?></span>
                                        <?= htmlspecialchars($c['title']) ?>
                                    </div>
                                    <span style="font-size: 0.65rem; color: var(--accent-red); font-weight: 700;">See All &rarr;</span>
                                </div>
                                <div style="display: flex; gap: 4px;">
                                    <div style="width: 32px; height: 46px; background: rgba(255,255,255,0.1); border-radius: 4px;"></div>
                                    <div style="width: 32px; height: 46px; background: rgba(255,255,255,0.1); border-radius: 4px;"></div>
                                    <div style="width: 32px; height: 46px; background: rgba(255,255,255,0.1); border-radius: 4px;"></div>
                                    <div style="width: 32px; height: 46px; background: rgba(255,255,255,0.05); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: var(--text-dim);">+</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ADD / EDIT CATEGORY -->
<div class="modal-overlay" id="categoryModal" style="display: none;">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="card-title" id="modalCategoryTitle">Add New Category</h3>
            <button type="button" class="btn-icon" onclick="closeCategoryModal()">&times;</button>
        </div>
        <form method="POST" action="categories.php" id="categoryForm">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" id="modalCategoryAction" value="add_category">
            <input type="hidden" name="id" id="modalCategoryId" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="cat_title">
                        <span>Category Title <span class="required-star">*</span></span>
                        <span class="label-sub">Visible on home shelf header</span>
                    </label>
                    <input type="text" id="cat_title" name="title" class="form-input" placeholder="e.g. Action Blockbusters, South Dubbed, Kids Zone" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cat_slug">
                            <span>Slug / Unique Key</span>
                            <span class="label-sub">Auto-created if empty</span>
                        </label>
                        <input type="text" id="cat_slug" name="slug" class="form-input" placeholder="e.g. action_movies">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="cat_order">
                            <span>Placing (Display Order) <span class="required-star">*</span></span>
                            <span class="label-sub">Lower number = top placement</span>
                        </label>
                        <input type="number" id="cat_order" name="display_order" class="form-input" value="<?= $totalCats + 1 ?>" min="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cat_subtitle">
                        <span>Subtitle / Tagline</span>
                        <span class="label-sub">Secondary description text</span>
                    </label>
                    <input type="text" id="cat_subtitle" name="subtitle" class="form-input" placeholder="e.g. High-octane blockbusters & thrillers">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cat_type">
                            <span>Content Type <span class="required-star">*</span></span>
                            <span class="label-sub">Catalog data source</span>
                        </label>
                        <select id="cat_type" name="content_type" class="form-input" onchange="toggleGenreSelector(this.value)">
                            <option value="movies">All Movies (Bollywood + Hollywood)</option>
                            <option value="bollywood">Bollywood Movies</option>
                            <option value="hollywood">Hollywood Movies</option>
                            <option value="series">Web Series</option>
                            <option value="liveTv">Live TV Channels</option>
                            <option value="genre">Specific Genre Filter</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="cat_sort">
                            <span>Sort Rule <span class="required-star">*</span></span>
                            <span class="label-sub">Shelf sorting sequence</span>
                        </label>
                        <select id="cat_sort" name="sort_by" class="form-input">
                            <option value="latest">Latest First (Release Year / Date)</option>
                            <option value="rating">Highest Rated (IMDb score)</option>
                            <option value="views">Most Views (Popularity)</option>
                            <option value="alphabetical">Alphabetical (A to Z)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="genreFilterGroup" style="display: none;">
                    <label class="form-label" for="cat_genre">
                        <span>Genre Filter</span>
                        <span class="label-sub">Only include items with this genre tag</span>
                    </label>
                    <select id="cat_genre" name="filter_genre" class="form-input">
                        <option value="">-- Choose Genre --</option>
                        <?php foreach ($allGenres as $g): ?>
                            <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="checkbox-card" style="margin: 0;">
                        <input type="checkbox" id="cat_enabled" name="is_enabled" value="1" checked>
                        <div class="label-content">
                            <strong>Active on Home Screen</strong>
                            <p>Enables this category chip and renders its shelf on the app home screen.</p>
                        </div>
                    </label>
                </div>
            </div>

            <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 0.75rem; background: rgba(0,0,0,0.2);">
                <button type="button" class="btn btn-secondary" onclick="closeCategoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="modalCategorySubmitBtn">Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleGenreSelector(val) {
    const group = document.getElementById('genreFilterGroup');
    if (group) {
        group.style.display = (val === 'genre') ? 'block' : 'none';
    }
}

function openAddCategoryModal() {
    document.getElementById('modalCategoryTitle').innerText = 'Add New Home Category';
    document.getElementById('modalCategoryAction').value = 'add_category';
    document.getElementById('modalCategoryId').value = '';
    document.getElementById('cat_title').value = '';
    document.getElementById('cat_slug').value = '';
    document.getElementById('cat_subtitle').value = '';
    document.getElementById('cat_type').value = 'movies';
    document.getElementById('cat_sort').value = 'latest';
    document.getElementById('cat_genre').value = '';
    document.getElementById('cat_order').value = '<?= $totalCats + 1 ?>';
    document.getElementById('cat_enabled').checked = true;
    document.getElementById('modalCategorySubmitBtn').innerText = 'Create Category';
    toggleGenreSelector('movies');

    document.getElementById('categoryModal').style.display = 'flex';
    document.getElementById('cat_title').focus();
}

function openEditCategoryModal(cat) {
    document.getElementById('modalCategoryTitle').innerText = 'Edit Category #' + cat.display_order;
    document.getElementById('modalCategoryAction').value = 'edit_category';
    document.getElementById('modalCategoryId').value = cat.id;
    document.getElementById('cat_title').value = cat.title || '';
    document.getElementById('cat_slug').value = cat.slug || '';
    document.getElementById('cat_subtitle').value = cat.subtitle || '';
    document.getElementById('cat_type').value = cat.content_type || 'movies';
    document.getElementById('cat_sort').value = cat.sort_by || 'latest';
    document.getElementById('cat_genre').value = cat.filter_genre || '';
    document.getElementById('cat_order').value = cat.display_order || '1';
    document.getElementById('cat_enabled').checked = (cat.is_enabled == 1);
    document.getElementById('modalCategorySubmitBtn').innerText = 'Save Changes';
    toggleGenreSelector(cat.content_type || 'movies');

    document.getElementById('categoryModal').style.display = 'flex';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

document.getElementById('categoryModal').addEventListener('click', function(e) {
    if (e.target === this) closeCategoryModal();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
