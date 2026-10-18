<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Client;

use Bakkbone\HelpCentre\Controllers\Controller;
use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Services\Auth;
use Bakkbone\HelpCentre\Services\Captcha;
use Bakkbone\HelpCentre\Services\Otp;
use Bakkbone\HelpCentre\Services\Tickets;
use Bakkbone\HelpCentre\Services\Users;

final class AuthController extends Controller
{
    private static function genericCodeMessage(): string
    {
        return __('If that email address is registered with us, we\'ve sent it a 6-digit sign-in code.');
    }

    public function login(): void
    {
        if (Auth::user()) {
            $this->redirect('/tickets');
        }
        $this->view('client/login', ['title' => __('Sign in')]);
    }

    private function afterLogin(): never
    {
        $return = (string)Session::get('user_return', '');
        Session::forget('user_return');
        if ($return !== '' && str_starts_with($return, App::basePath() . '/') && !str_contains($return, '//')) {
            \Bakkbone\HelpCentre\Core\View::redirect(App::baseUrl() . substr($return, strlen(App::basePath())));
        }
        if ($return !== '' && str_starts_with($return, App::baseUrl())) {
            \Bakkbone\HelpCentre\Core\View::redirect($return);
        }
        $this->redirect('/tickets');
    }

    public function loginPassword(): void
    {
        if (!Settings::bool('client_password_login')) {
            $this->redirect('/login');
        }
        $email = strtolower(Request::str('email'));
        $password = (string)Request::post('password', '');
        if (Settings::bool('captcha_on_login') && !Captcha::verify()) {
            $this->failWith(__('Please complete the spam check.'), '/login');
        }
        if (Auth::throttled('user', $email)) {
            $this->failWith(Auth::lockoutMessage(), '/login');
        }
        $user = Users::byEmail($email);
        if (!$user || !Auth::verifyPassword('users', $user, $password)) {
            Auth::recordAttempt('user', $email, false);
            $this->failWith(Settings::bool('client_passwordless') ? __('Incorrect email or password. You can also sign in with an emailed code.') : __('Incorrect email or password.'), '/login');
        }
        if ($user['status'] !== 'active') {
            $this->failWith(__('Your account is locked. Please contact us.'), '/login');
        }
        Auth::recordAttempt('user', $email, true);
        Auth::loginUser($user);
        $this->afterLogin();
    }

    public function sendCode(): void
    {
        if (!Settings::bool('client_passwordless')) {
            $this->redirect('/login');
        }
        $email = strtolower(Request::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failWith(__('Please enter a valid email address.'), '/login');
        }
        if (Settings::bool('captcha_on_login') && !Captcha::verify()) {
            $this->failWith(__('Please complete the spam check.'), '/login');
        }
        if (Auth::throttled('user_code', $email)) {
            $this->failWith(Auth::lockoutMessage(), '/login');
        }
        Auth::recordAttempt('user_code', $email, false);
        $user = Users::byEmail($email);
        if ($user && $user['status'] === 'active') {
            Otp::issue('user', (int)$user['id'], 'login', (string)$user['email'], (string)$user['name']);
        }
        Session::set('user_pending', ['id' => $user && $user['status'] === 'active' ? (int)$user['id'] : 0, 'email' => $email, 'purpose' => 'login']);
        $this->flash('info', self::genericCodeMessage());
        $this->redirect('/login/verify');
    }

    public function verifyForm(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'login') {
            $this->redirect('/login');
        }
        $this->view('client/verify_code', ['title' => __('Enter your code'), 'email' => $p['email'], 'action' => '/login/verify', 'resend' => '/login/code']);
    }

    public function verify(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'login') {
            $this->redirect('/login');
        }
        if (Auth::throttled('user_otp', (string)$p['email'])) {
            $this->failWith(Auth::lockoutMessage(), '/login/verify');
        }
        $ok = $p['id'] && Otp::verify('user', (int)$p['id'], 'login', Request::str('code'));
        if (!$ok) {
            Auth::recordAttempt('user_otp', (string)$p['email'], false);
            $this->failWith(__('That code is incorrect or has expired.'), '/login/verify');
        }
        Auth::recordAttempt('user_otp', (string)$p['email'], true);
        $user = Users::find((int)$p['id']);
        if (!$user || $user['status'] !== 'active') {
            $this->failWith(__('Your account is not active.'), '/login');
        }
        Auth::loginUser($user);
        $this->afterLogin();
    }

    public function logout(): void
    {
        Auth::logoutUser();
        $this->flash('success', __('You have been signed out.'));
        $this->redirect('/');
    }

    public function register(): void
    {
        if (Settings::get('client_registration') !== 'public') {
            $this->notFound();
        }
        $this->view('client/register', ['title' => __('Create an account')]);
    }

    public function registerSubmit(): void
    {
        if (Settings::get('client_registration') !== 'public') {
            $this->notFound();
        }
        $name = Request::str('name');
        $email = strtolower(Request::str('email'));
        $phone = Request::str('phone');
        $password = (string)Request::post('password', '');
        $errors = [];
        if (!Captcha::verify()) {
            $errors[] = __('Please complete the spam check.');
        }
        if ($name === '') {
            $errors[] = __('Please enter your name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Please enter a valid email address.');
        }
        $needPassword = Settings::bool('client_password_login') && (!Settings::bool('client_passwordless') || $password !== '');
        if ($needPassword && ($err = Auth::passwordError($password, (string)Request::post('password_confirm', '')))) {
            $errors[] = $err;
        }
        if (Users::isBanned($email)) {
            $errors[] = __('This email address cannot be registered.');
        }
        if ($errors) {
            $this->failWith($errors, '/register');
        }
        if (Auth::throttled('register', $email)) {
            $this->failWith(Auth::lockoutMessage(), '/register');
        }
        Auth::recordAttempt('register', $email, false);
        $user = Users::byEmail($email);
        if ($user && ($user['password_hash'] || $user['verified'])) {
            $this->flash('info', Settings::bool('client_passwordless') ? __('You already have an account. Sign in below - we can email you a code.') : __('You already have an account. Sign in below or reset your password.'));
            $this->redirect('/login');
        }
        // An existing user's details change only after the code proves they own the address.
        $user ??= Users::findOrCreate($email, $name, $phone ?: null);
        Session::set('user_pending', [
            'id' => (int)$user['id'],
            'email' => $email,
            'purpose' => 'verify',
            'hash' => $needPassword ? Auth::hash($password) : null,
            'name' => $name,
            'phone' => $phone,
        ]);
        Otp::issue('user', (int)$user['id'], 'verify', $email, $name);
        $this->flash('info', __('We\'ve emailed you a 6-digit code to confirm your address.'));
        $this->redirect('/register/verify');
    }

    public function registerVerifyForm(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'verify') {
            $this->redirect('/register');
        }
        $this->view('client/verify_code', ['title' => __('Confirm your email'), 'email' => $p['email'], 'action' => '/register/verify', 'resend' => null]);
    }

    public function registerVerify(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'verify') {
            $this->redirect('/register');
        }
        if (Auth::throttled('user_otp', (string)$p['email'])) {
            $this->failWith(Auth::lockoutMessage(), '/register/verify');
        }
        if (!Otp::verify('user', (int)$p['id'], 'verify', Request::str('code'))) {
            Auth::recordAttempt('user_otp', (string)$p['email'], false);
            $this->failWith(__('That code is incorrect or has expired.'), '/register/verify');
        }
        $update = ['verified' => 1, 'updated_at' => DB::now()];
        if (($p['name'] ?? '') !== '') {
            $update['name'] = mb_substr((string)$p['name'], 0, 190);
        }
        if (($p['phone'] ?? '') !== '') {
            $update['phone'] = (string)$p['phone'];
        }
        if (!empty($p['hash'])) {
            $update['password_hash'] = $p['hash'];
        }
        App::db()->update('users', $update, 'id = ?', [$p['id']]);
        Auth::loginUser((array)Users::find((int)$p['id']));
        $this->flash('success', __('Welcome! Your account is ready.'));
        $this->afterLogin();
    }

    public function reset(): void
    {
        if (!Settings::bool('client_password_login')) {
            $this->redirect('/login');
        }
        $this->view('client/reset', ['title' => __('Reset your password')]);
    }

    public function resetSubmit(): void
    {
        $email = strtolower(Request::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->failWith(__('Please enter a valid email address.'), '/reset');
        }
        if (Auth::throttled('user_reset', $email)) {
            $this->failWith(Auth::lockoutMessage(), '/reset');
        }
        Auth::recordAttempt('user_reset', $email, false);
        $user = Users::byEmail($email);
        if ($user && $user['status'] === 'active') {
            Otp::issue('user', (int)$user['id'], 'reset', $email, (string)$user['name']);
        }
        Session::set('user_pending', ['id' => $user ? (int)$user['id'] : 0, 'email' => $email, 'purpose' => 'reset']);
        $this->flash('info', __('If that email address is registered, we\'ve sent it a reset code.'));
        $this->redirect('/reset/verify');
    }

    public function resetVerifyForm(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'reset') {
            $this->redirect('/reset');
        }
        $this->view('client/reset_verify', ['title' => __('Choose a new password'), 'email' => $p['email']]);
    }

    public function resetVerify(): void
    {
        $p = Session::get('user_pending');
        if (!$p || ($p['purpose'] ?? '') !== 'reset') {
            $this->redirect('/reset');
        }
        if (Auth::throttled('user_otp', (string)$p['email'])) {
            $this->failWith(Auth::lockoutMessage(), '/reset/verify');
        }
        $password = (string)Request::post('password', '');
        if ($err = Auth::passwordError($password, (string)Request::post('password_confirm', ''))) {
            $this->failWith($err, '/reset/verify');
        }
        if (!$p['id'] || !Otp::verify('user', (int)$p['id'], 'reset', Request::str('code'))) {
            Auth::recordAttempt('user_otp', (string)$p['email'], false);
            $this->failWith(__('That code is incorrect or has expired.'), '/reset/verify');
        }
        App::db()->update('users', ['password_hash' => Auth::hash($password), 'verified' => 1, 'updated_at' => DB::now()], 'id = ?', [$p['id']]);
        Auth::loginUser((array)Users::find((int)$p['id']));
        $this->flash('success', __('Your password has been updated.'));
        $this->redirect('/tickets');
    }

    public function status(): void
    {
        $this->view('client/status', ['title' => __('Track a ticket')]);
    }

    public function statusSubmit(): void
    {
        $email = strtolower(Request::str('email'));
        $number = trim(Request::str('number'), " #");
        if (!Captcha::verify()) {
            $this->failWith(__('Please complete the spam check.'), '/status');
        }
        if (Auth::throttled('status', $email . '|' . Request::ip())) {
            $this->failWith(Auth::lockoutMessage(), '/status');
        }
        Auth::recordAttempt('status', $email . '|' . Request::ip(), false);
        $t = Tickets::byNumber($number);
        $user = Users::byEmail($email);
        $ok = $t && $user && $user['status'] === 'active' && Tickets::isParticipant($t, (int)$user['id']);
        if ($ok) {
            Otp::issue('user', (int)$user['id'], 'login', $email, (string)$user['name']);
            Session::set('user_return', App::url('/tickets/' . $t['number']));
        }
        Session::set('user_pending', ['id' => $ok ? (int)$user['id'] : 0, 'email' => $email, 'purpose' => 'login']);
        $this->flash('info', __('If the email and ticket number match our records, we\'ve emailed a 6-digit code to access your ticket.'));
        $this->redirect('/login/verify');
    }
}
