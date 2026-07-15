<?php

it('redirects a guest visiting the admin panel to Slack OAuth', function () {
    $this->get('/admin')->assertRedirect(route('slack.login'));
});

it('does not show a password login form at /admin/login', function () {
    $this->get('/admin/login')->assertNotFound();
});
