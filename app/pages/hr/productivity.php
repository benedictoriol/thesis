<?php

require_once __DIR__ . '/../../core/guard.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../includes/staff_helpers.php';

require_role(['owner', 'hr']);

$currentUser = current_user();
$pageTitle = 'Productivity Metrics';

$errors = [];
$employees = [];
$employeeMetrics = [];

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

if (!$errors) {
    try {
        $employeeStmt = db()->prepare(
            'SELECT u.id, u.fullname, u.email, ss.position
             FROM shop_staff ss
             JOIN users u ON u.id = ss.user_id
             WHERE ss.shop_id = :shop_id
               AND ss.role = :role
               AND ss.status = :status
             ORDER BY u.fullname'
        );
        $employeeStmt->execute([
            'shop_id' => $shop['id'],
            'role' => 'employee',
            'status' => 'active',
        ]);
        $employees = $employeeStmt->fetchAll();

        $ticketMetrics = [];
        $attendanceMetrics = [];
        $lateThresholdHours = 24;

        if ($employees) {
            $ticketFilters = 'o.shop_id = :shop_id AND jt.status = :status';
            $ticketParams = [
                'shop_id' => $shop['id'],
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
                'SELECT jt.assigned_to_user_id AS user_id,
                        COUNT(*) AS jobs_done,
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
                 WHERE ' . $ticketFilters . '
                 GROUP BY jt.assigned_to_user_id'
            );
            $ticketStmt->execute($ticketParams);
            foreach ($ticketStmt->fetchAll() as $row) {
                $ticketMetrics[(int) $row['user_id']] = $row;
            }

            $attendanceFilters = 'shop_id = :shop_id AND status = :status';
            $attendanceParams = [
                'shop_id' => $shop['id'],
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
                'SELECT user_id,
                        SUM(hours) AS total_hours,
                        COUNT(*) AS days_logged
                 FROM timesheets
                 WHERE ' . $attendanceFilters . '
                 GROUP BY user_id'
            );
            $attendanceStmt->execute($attendanceParams);
            foreach ($attendanceStmt->fetchAll() as $row) {
                $attendanceMetrics[(int) $row['user_id']] = $row;
            }
        }

        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];
            $ticketRow = $ticketMetrics[$employeeId] ?? null;
            $attendanceRow = $attendanceMetrics[$employeeId] ?? null;

            $avgMinutes = $ticketRow ? (float) ($ticketRow['avg_turnaround_minutes'] ?? 0) : 0;
            $employeeMetrics[$employeeId] = [
                'jobs_done' => (int) ($ticketRow['jobs_done'] ?? 0),
                'avg_turnaround_hours' => $avgMinutes > 0 ? round($avgMinutes / 60, 2) : 0,
                'late_jobs' => (int) ($ticketRow['late_jobs'] ?? 0),
                'attendance_hours' => (float) ($attendanceRow['total_hours'] ?? 0),
                'attendance_days' => (int) ($attendanceRow['days_logged'] ?? 0),
            ];
        }
    } catch (PDOException $exception) {
        $errors[] = 'Unable to load productivity metrics right now.';
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Productivity Metrics</h1>
        <p class="text-muted mb-0">Monitor ticket throughput, turnaround, and attendance for each employee.</p>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
        <label for="range" class="small text-muted">Range</label>
        <select id="range" name="range" class="form-select form-select-sm">
            <?php foreach ($rangeOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $range ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
    </form>
</div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (!$errors): ?>
    <div class="alert alert-info">
        Showing <strong><?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?></strong> productivity for
        <strong><?= htmlspecialchars($shop['name'] ?? 'your shop', ENT_QUOTES, 'UTF-8') ?></strong>.
        <span class="d-block small text-muted">Late jobs = tickets taking more than 24 hours from start to finish.</span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if ($employees): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Jobs done</th>
                            <th>Avg turnaround (hrs)</th>
                            <th>Late jobs</th>
                            <th>Attendance hours</th>
                            <th>Days logged</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $metrics = $employeeMetrics[(int) $employee['id']] ?? [
                                'jobs_done' => 0,
                                'avg_turnaround_hours' => 0,
                                'late_jobs' => 0,
                                'attendance_hours' => 0,
                                'attendance_days' => 0,
                            ];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($employee['fullname'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($employee['position'] ?? 'Employee', ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= (int) $metrics['jobs_done'] ?></td>
                                <td><?= htmlspecialchars(number_format((float) $metrics['avg_turnaround_hours'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $metrics['late_jobs'] ?></td>
                                <td><?= htmlspecialchars(number_format((float) $metrics['attendance_hours'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $metrics['attendance_days'] ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="/hr/productivity/<?= (int) $employee['id'] ?>?range=<?= htmlspecialchars($range, ENT_QUOTES, 'UTF-8') ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No active employees have been added to this shop yet.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>