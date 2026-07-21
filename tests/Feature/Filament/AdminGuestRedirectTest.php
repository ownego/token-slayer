<?php

it('redirects a guest visiting the dashboard panel to Slack OAuth', function () {
    $this->get('/dashboard')->assertRedirect(route('slack.login'));
});

it('does not show a password login form at /dashboard/login', function () {
    $this->get('/dashboard/login')->assertNotFound();
});

it('redirects the legacy /admin path to /dashboard', function () {
    $this->get('/admin')->assertRedirect('/dashboard');
});

it('redirects legacy /admin sub-paths to their /dashboard equivalent', function () {
    $this->get('/admin/accounts')->assertRedirect('/dashboard/accounts');
});
