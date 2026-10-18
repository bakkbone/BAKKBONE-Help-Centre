<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Core\Totp;
use Bakkbone\HelpCentre\Core\View;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Otp;
use Bakkbone\HelpCentre\Services\Staff;

final class AuthController extends Controller
{
    protected string $layout = 'layouts/staff_auth';

    public function login(): void
    {
        if (Auth::staff()) {
            $this->redirect('/panel');
        }
        $this->view('staff/login', ['title' => __('Agent sign in')]);
    }

    private function firstFactorPassed(array $s): never
    {
        if ($s['totp_enabled'] && $s['totp_secret']) {
            Session::regenerate();
            Session::set('staff_pending_2fa', ['id' => (int)$s['id'], 'at' => time()]);
            $this->redirect('/panel/login/2fa');
        }
        $this->complete($s);
    }

    private function complete(array $s): never
    {
        Auth::loginStaff($s);
        Audit::log('staff.login', 'staff', (int)$s['id']);
        $return = (string)Session::get('staff_return', '');
        Session::forget('staff_return');
        $base = App::basePath();
        if ($return !== '' && str_starts_with($return, $base . '/panel') && !str_contains($return, '//')) {
            View::redirect(App::baseUrl() . substr($return, strlen($base)));
        }
        $this->redirect('/panel');
    }

    public function loginPassword(): void
    {
        if (!Settings::bool('staff_password_login')) {
            $this->redirect('/panel/login');
        }
        $login = Request::str('login');
        if (Auth::throttled('staff', $login)) {
            $this->failWith(Auth::lockoutMessage(), '/panel/login');
        }
        $s = Staff::byLogin($login);
        if (!$s || !Auth::verifyPassword('staff', $s, (string)Request::post('password', ''))) {
            Auth::recordAttempt('staff', $login, false);
            $this->failWith(__('Incorrect username or password.'), '/panel/login');
        }
        if (!$s['active']) {
            $this->failWith(__('Your account is disabled.'), '/panel/login');
        }
        Auth::recordAttempt('staff', $login, true);
        $this->firstFactorPassed($s);
    }

    public function sendCode(): void
    {
        if (!Settings::bool('staff_passwordless')) {
            $this->redirect('/panel/login');
        }
        $login = Request::str('login');
        if (Auth::throttled('staff_code', $login)) {
            $this->failWith(Auth::lockoutMessage(), '/panel/login');
        }
        Auth::recordAttempt('staff_code', $login, false);
        $s = Staff::byLogin($login);
        if ($s && $s['active']) {
            Otp::issue('staff', (int)$s['id'], 'login', (string)$s['email'], Staff::name($s));
        }
        Session::set('staff_pending', ['id' => $s && $s['active'] ? (int)$s['id'] : 0, 'login' => $login, 'purpose' => 'login']);
        $this->flash('info', __('If that account exists, a 6-digit sign-in code has been emailed to it.'));
        $this->redirect('/panel/login/verify');
    }

    public function verifyForm(): void
    {
        $p = Session::get('staff_pending');
        if (!$p || $p['purpose'] !== 'login') {
            $this->redirect('/panel/login');
        }
        $this->view('staff/verify_code', ['title' => __('Enter your code'), 'login' => $p['login'], 'action' => '/panel/login/verify', 'reset' => false]);
    }

    public function verify(): void
    {
        $p = Session::get('staff_pending');
        if (!$p || $p['purpose'] !== 'login') {
            $this->redirect('/panel/login');
        }
        if (Auth::throttled('staff_otp', (string)$p['login'])) {
            $this->failWith(Auth::lockoutMessage(), '/panel/login/verify');
        }
        if (!$p['id'] || !Otp::verify('staff', (int)$p['id'], 'login', Request::str('code'))) {
            Auth::recordAttempt('staff_otp', (string)$p['login'], false);
            $this->failWith(__('That code is incorrect or has expired.'), '/panel/login/verify');
        }
        Auth::recordAttempt('staff_otp', (string)$p['login'], true);
        Session::forget('staff_pending');
        $s = Staff::find((int)$p['id']);
        if (!$s || !$s['active']) {
            $this->redirect('/panel/login');
        }
        $this->firstFactorPassed($s);
    }

    public function twoFactorForm(): void
    {
        $p = Session::get('staff_pending_2fa');
        if (!$p || time() - (int)$p['at'] > 600) {
            $this->redirect('/panel/login');
        }
        $this->view('staff/two_factor', ['title' => __('Two-factor authentication')]);
    }

    public function twoFactor(): void
    {
        $p = Session::get('staff_pending_2fa');
        if (!$p || time() - (int)$p['at'] > 600) {
            $this->redirect('/panel/login');
        }
        $s = Staff::find((int)$p['id']);
        if (!$s || !$s['active']) {
            $this->redirect('/panel/login');
        }
        if (Auth::throttled('staff_2fa', (string)$s['id'])) {
            $this->failWith(Auth::lockoutMessage(), '/panel/login/2fa');
        }
        $secret = (string)\Bakkbone\HelpCentre\Core\Crypto::decrypt($s['totp_secret']);
        $counter = Totp::verify($secret, Request::str('code'), $s['totp_last_counter'] !== null ? (int)$s['totp_last_counter'] : null);
        if ($counter === null) {
            Auth::recordAttempt('staff_2fa', (string)$s['id'], false);
            $this->failWith(__('That authentication code is not valid.'), '/panel/login/2fa');
        }
        Auth::recordAttempt('staff_2fa', (string)$s['id'], true);
        App::db()->update('staff', ['totp_last_counter' => $counter], 'id = ?', [$s['id']]);
        Session::forget('staff_pending_2fa');
        $this->complete($s);
    }

    public function logout(): void
    {
        if ($id = Auth::staffId()) {
            App::db()->delete('ticket_locks', 'staff_id = ?', [$id]);
            Audit::log('staff.logout', 'staff', $id);
        }
        Auth::logoutStaff();
        $this->flash('success', __('You have been signed out.'));
        $this->redirect('/panel/login');
    }

    public function reset(): void
    {
        $this->view('staff/reset', ['title' => __('Reset password')]);
    }

    public function resetSubmit(): void
    {
        $login = Request::str('login');
        if (Auth::throttled('staff_reset', $login)) {
            $this->failWith(Auth::lockoutMessage(), '/panel/reset');
        }
        Auth::recordAttempt('staff_reset', $login, false);
        $s = Staff::byLogin($login);
        if ($s && $s['active']) {
            Otp::issue('staff', (int)$s['id'], 'reset', (string)$s['email'], Staff::name($s));
        }
        Session::set('staff_pending', ['id' => $s && $s['active'] ? (int)$s['id'] : 0, 'login' => $login, 'purpose' => 'reset']);
        $this->flash('info', __('If that account exists, a reset code has been emailed to it.'));
        $this->redirect('/panel/reset/verify');
    }

    public function resetVerifyForm(): void
    {
        $p = Session::get('staff_pending');
        if (!$p || $p['purpose'] !== 'reset') {
            $this->redirect('/panel/reset');
        }
        $this->view('staff/verify_code', ['title' => __('Choose a new password'), 'login' => $p['login'], 'action' => '/panel/reset/verify', 'reset' => true]);
    }

    public function resetVerify(): void
    {
        $p = Session::get('staff_pending');
        if (!$p || $p['purpose'] !== 'reset') {
            $this->redirect('/panel/reset');
        }
        if (Auth::throttled('staff_otp', (string)$p['login'])) {
            $this->failWith(Auth::lockoutMessage(), '/panel/reset/verify');
        }
        $pw = (string)Request::post('password', '');
        if ($err = Auth::passwordError($pw, (string)Request::post('password_confirm', ''))) {
            $this->failWith($err, '/panel/reset/verify');
        }
        if (!$p['id'] || !Otp::verify('staff', (int)$p['id'], 'reset', Request::str('code'))) {
            Auth::recordAttempt('staff_otp', (string)$p['login'], false);
            $this->failWith(__('That code is incorrect or has expired.'), '/panel/reset/verify');
        }
        App::db()->update('staff', ['password_hash' => Auth::hash($pw), 'updated_at' => DB::now()], 'id = ?', [$p['id']]);
        Session::forget('staff_pending');
        Audit::log('staff.password_reset', 'staff', (int)$p['id']);
        $s = Staff::find((int)$p['id']);
        $this->flash('success', __('Password updated.'));
        $this->firstFactorPassed($s);
    }
}
