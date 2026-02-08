<?php
$pageTitle = 'Thesis Portal';
require __DIR__ . '/../includes/app_header.php';
?>
<div class="landing-hero text-center">
    <span class="badge rounded-pill bg-primary-subtle text-primary mb-3">Thesis Portal</span>
    <h1 class="display-6 fw-semibold mb-3">Bring your academic ideas to life with the right partners.</h1>
    <p class="lead text-muted mb-4">
        Discover vetted shop owners, track deliverables, and collaborate with experts in one secure workspace.
    </p>
    <div class="d-flex flex-column flex-sm-row justify-content-center gap-3">
        <a class="btn btn-primary btn-lg" href="/auth/login">Log in</a>
        <a class="btn btn-outline-primary btn-lg" href="/auth/register_client">Register as a client</a>
        <a class="btn btn-outline-secondary btn-lg" href="/auth/register_owner">Register as a shop owner</a>
    </div>
</div>

<div class="row g-3 mt-4">
    <div class="col-md-4">
        <div class="landing-feature h-100">
            <div class="landing-icon bg-primary-subtle text-primary">01</div>
            <h3 class="h5 fw-semibold">Browse trusted providers</h3>
            <p class="text-muted mb-0">
                Explore curated shops, compare portfolios, and shortlist the teams that match your thesis goals.
            </p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="landing-feature h-100">
            <div class="landing-icon bg-info-subtle text-info">02</div>
            <h3 class="h5 fw-semibold">Collaborate in one hub</h3>
            <p class="text-muted mb-0">
                Keep messaging, quotations, and project updates neatly organized from kickoff to completion.
            </p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="landing-feature h-100">
            <div class="landing-icon bg-success-subtle text-success">03</div>
            <h3 class="h5 fw-semibold">Stay on schedule</h3>
            <p class="text-muted mb-0">
                Monitor milestones, payments, and documentation while receiving real-time notifications.
            </p>
        </div>
    </div>
</div>

<div class="landing-cta text-center mt-4">
    <p class="text-muted mb-2">Already have an account?</p>
    <a class="btn btn-link fw-semibold" href="/auth/login">Sign in to continue →</a>
</div>
<?php require __DIR__ . '/../includes/app_footer.php'; ?>
