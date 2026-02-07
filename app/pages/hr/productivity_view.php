<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Employee Productivity';

$errors = [];
$employee = null;
$ticketMetrics = [
    'jobs_done' => 0,
    'avg_turnaround_hours' => 0,
    'late_jobs' => 0,
];
$attendanceMetrics = [
    'attendance_hours' => 0,
    'attendance_days' => 0,
];
$recentTickets = [];
$recentTimesheets = [];

$employeeId = (int) ($_GET['employee_id'] ?? 0);

$rangeOptions = [
    'week' => 'Last 7 days',
    'month' => 'Last 30 days',
    'quarter' => 'Last 90 days',
    'year' => 'Last 365 days',
    'all' => 'All time',
];

$range = strtolower(trim((string) ($_GET['range'] ?? 'month')));
if (!array_key_exists($range, $rangeOptions)) {
    $range = 'month';
}

$rangeStart = null;
$rangeEnd = null;
$rangeLabel = $rangeOptions[$range] ?? 'Last 30 days';
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

switch ($range) {
    case 'week':
        $rangeStart = $now->modify('-7 days')->setTime(0, 0, 0);
        $rangeEnd = $now->modify('+1 day')->setTime(0, 0, 0);
        break;
    case 'month':
        $rangeStart = $now->modify('-30 days')->setTime(0, 0, 0);
        $rangeEnd = $now->modify('+1 day')->setTime(0, 0, 0);
        break;
    case 'quarter':
        $rangeStart = $now->modify('-90 days')->setTime(0, 0, 0);
        $rangeEnd = $now->modify('+1 day')->setTime(0, 0, 0);
        break;
    case 'year':
        $rangeStart = $now->modify('-365 days')->setTime(0, 0, 0);
        $rangeEnd = $now->modify('+1 day')->setTime(0, 0, 0);
        break;
    case 'all':
    default:
        $rangeStart = null;
        $rangeEnd = null;
        break;
}

$shop = load_shop_for_staff_user($currentUser);
if (!$shop) {
    $errors[] = 'No shop is linked to this account yet.';
}

$hasJobTickets = table_exists('job_tickets');
$hasTimesheets = table_exists('timesheets');

if (!$hasJobTickets) {
    $errors[] = 'Job tickets table is not available yet.';
}

if (!$hasTimesheets) {
    $errors[] = 'Timesheets table is not available yet.';
}

if (!$employeeId) {
    $errors[] = 'Employee not found.';
}

if (!$errors) {
    try {
        $employeeStmt = db()->prepare(
            'SELECT u.id, u.fullname, u.email, ss.position
             FROM shop_staff ss
             JOIN users u ON u.id = ss.user_id
             WHERE ss.shop_id = :shop_id
               AND ss.user_id = :user_id
               AND ss.role = :role
               AND ss.status = :status
             LIMIT 1'
        );
        $employeeStmt->execute([
            'shop_id' => $shop['id'],
            'user_id' => $employeeId,
            'role' => 'employee',
            'status' => 'active',
        ]);
        $employee = $employeeStmt->fetch();

        if (!$employee) {
            $errors[] = 'Employee not found for this shop.';
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load employee details.';
    }
}

if (!$errors && $employee) {
    try {
        $lateThresholdHours = 24;
        $ticketFilters = 'o.shop_id = :shop_id AND jt.assigned_to_user_id = :user_id AND jt.status = :status';
        $ticketParams = [
            'shop_id' => $shop['id'],
            'user_id' => $employeeId,
            'status' => 'done',
            'late_threshold' => $lateThresholdHours,
        ];

        if ($rangeStart instanceof DateTimeImmutable) {
            $ticketFilters .= ' AND jt.finished_at >= :range_start';
            $ticketParams['range_start'] = $rangeStart->format('Y-m-d H:i:s');
        }
        if ($rangeEnd instanceof DateTimeImmutable) {
            $ticketFilters .= ' AND jt.finished_at < :range_end';
            $ticketParams['range_end'] = $rangeEnd->format('Y-m-d H:i:s');
        }

        $ticketStmt = db()->prepare(
            'SELECT COUNT(*) AS jobs_done,
                    AVG(CASE
                            WHEN jt.started_at IS NOT NULL AND jt.finished_at IS NOT NULL
                                THEN TIMESTAMPDIFF(MINUTE, jt.started_at, jt.finished_at)
                            ELSE NULL
                        END) AS avg_turnaround_minutes,
                    SUM(CASE
                            WHEN jt.started_at IS NOT NULL
                                 AND jt.finished_at IS NOT NULL
                                 AND TIMESTAMPDIFF(HOUR, jt.started_at, jt.finished_at) > :late_threshold
                                THEN 1
                            ELSE 0
                        END) AS late_jobs
             FROM job_tickets jt
             JOIN orders o ON o.id = jt.order_id
             WHERE ' . $ticketFilters
        );
        $ticketStmt->execute($ticketParams);
        $ticketSummary = $ticketStmt->fetch();

        if ($ticketSummary) {
            $avgMinutes = (float) ($ticketSummary['avg_turnaround_minutes'] ?? 0);
            $ticketMetrics = [
                'jobs_done' => (int) ($ticketSummary['jobs_done'] ?? 0),
                'avg_turnaround_hours' => $avgMinutes > 0 ? round($avgMinutes / 60, 2) : 0,
                'late_jobs' => (int) ($ticketSummary['late_jobs'] ?? 0),
            ];
        }

        $attendanceFilters = 'shop_id = :shop_id AND user_id = :user_id AND status = :status';
        $attendanceParams = [
            'shop_id' => $shop['id'],
            'user_id' => $employeeId,
            'status' => 'approved',
        ];

        if ($rangeStart instanceof DateTimeImmutable) {
            $attendanceFilters .= ' AND work_date >= :range_start';
            $attendanceParams['range_start'] = $rangeStart->format('Y-m-d');
        }
        if ($rangeEnd instanceof DateTimeImmutable) {
            $attendanceFilters .= ' AND work_date < :range_end';
            $attendanceParams['range_end'] = $rangeEnd->format('Y-m-d');
        }

        $attendanceStmt = db()->prepare(
            'SELECT SUM(hours) AS total_hours,
                    COUNT(*) AS days_logged
             FROM timesheets
             WHERE ' . $attendanceFilters
        );
        $attendanceStmt->execute($attendanceParams);
        $attendanceSummary = $attendanceStmt->fetch();

        if ($attendanceSummary) {
            $attendanceMetrics = [
                'attendance_hours' => (float) ($attendanceSummary['total_hours'] ?? 0),
                'attendance_days' => (int) ($attendanceSummary['days_logged'] ?? 0),
            ];
        }

        $ticketDetailStmt = db()->prepare(
            'SELECT jt.id,
                    jt.step,
                    jt.status,
                    jt.started_at,
                    jt.finished_at,
                    o.id AS order_id,
                    TIMESTAMPDIFF(MINUTE, jt.started_at, jt.finished_at) AS duration_minutes
             FROM job_tickets jt
             JOIN orders o ON o.id = jt.order_id
             WHERE ' . $ticketFilters . '
             ORDER BY jt.finished_at DESC
             LIMIT 20'
        );
        $ticketDetailStmt->execute($ticketParams);
        $recentTickets = $ticketDetailStmt->fetchAll();

        $timesheetStmt = db()->prepare(
            'SELECT work_date, hours, status, notes
             FROM timesheets
             WHERE ' . $attendanceFilters . '
             ORDER BY work_date DESC
             LIMIT 20'
        );
        $timesheetStmt->execute($attendanceParams);
        $recentTimesheets = $timesheetStmt->fetchAll();
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load productivity details.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Employee Productivity</h1>
        <p class="text-muted mb-0">Deep dive into turnaround time and attendance trends.</p>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
        <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
        <label for="range" class="small text-muted">Range</label>
        <select id="range" name="range" class="form-select form-select-sm">
            <?php foreach ($rangeOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $range ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
        <a class="btn btn-sm btn-outline-secondary" href="/hr/productivity?range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">Back</a>
    </form>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors && $employee): ?>
    <div class="alert alert-info">
        Showing <strong><?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?></strong> metrics for
        <strong><?= htmlspecialchars($employee['fullname'] ?? 'Employee', ENT_QUOTES, 'UTF-8') ?></strong>.
        <span class="d-block small text-muted">Late jobs = tickets taking more than 24 hours from start to finish.</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Jobs completed</div>
                    <div class="fs-4 fw-semibold"><?= (int) $ticketMetrics['jobs_done'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Avg turnaround (hrs)</div>
                    <div class="fs-4 fw-semibold"><?= htmlspecialchars(number_format((float) $ticketMetrics['avg_turnaround_hours'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Late jobs</div>
                    <div class="fs-4 fw-semibold"><?= (int) $ticketMetrics['late_jobs'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Attendance hours</div>
                    <div class="fs-4 fw-semibold"><?= htmlspecialchars(number_format((float) $attendanceMetrics['attendance_hours'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted small"><?= (int) $attendanceMetrics['attendance_days'] ?> days logged</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Recent completed tickets</h2>
                    <?php if ($recentTickets): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Step</th>
                                    <th>Started</th>
                                    <th>Finished</th>
                                    <th>Duration (hrs)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentTickets as $ticket): ?>
                                    <?php
                                    $durationHours = null;
                                    if ($ticket['duration_minutes'] !== null) {
                                        $durationHours = round(((float) $ticket['duration_minutes']) / 60, 2);
                                    }
                                    ?>
                                    <tr>
                                        <td>#<?= htmlspecialchars($ticket['id'] ?? '', ENT_QUOTES, 'UTF-8') ?> (Order <?= htmlspecialchars($ticket['order_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</td>
                                        <td><?= htmlspecialchars($ticket['step'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($ticket['started_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($ticket['finished_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $durationHours !== null ? htmlspecialchars(number_format($durationHours, 2), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No completed tickets yet for this timeframe.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6">Recent timesheets</h2>
                    <?php if ($recentTimesheets): ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Hours</th>
                                    <th>Notes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentTimesheets as $timesheet): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($timesheet['work_date'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($timesheet['hours'] ?? '0', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars($timesheet['notes'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No approved timesheets yet for this timeframe.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
