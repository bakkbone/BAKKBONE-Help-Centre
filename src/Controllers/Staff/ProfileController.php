<?php
declare(strict_types=1);

namespace Bakkbone\HelpCentre\Controllers\Staff;

use Bakkbone\HelpCentre\Core\App;
use Bakkbone\HelpCentre\Core\Audit;
use Bakkbone\HelpCentre\Core\Crypto;
use Bakkbone\HelpCentre\Core\DB;
use Bakkbone\HelpCentre\Core\Html;
use Bakkbone\HelpCentre\Core\Request;
use Bakkbone\HelpCentre\Core\Session;
use Bakkbone\HelpCentre\Core\Settings;
use Bakkbone\HelpCentre\Core\Totp;
use Bakkbone\HelpCentre\Services\Auth;

final class ProfileController extends StaffController
{
    public function edit(): void
    {
        $pendingSecret = Session::get('totp_setup');
        $this->view('staff/profile', [
            'title' => __('My profile'), 'me' => $this->staff,
            'totpSecret' => $pendingSecret,
            'totpUri' => $pendingSecret ? Totp::uri($pendingSecret, (string)$this->staff['email'], (string)Settings::get('helpdesk_name')) : null,
        ]);
    }

    public function update(): void
    {
        $first = Request::str('first_name');
        if ($first === '') {
            $this->failWith(__('First name is required.'), '/panel/profile');
        }
        $data = [
            'first_name' => mb_substr($first, 0, 80),
            'last_name' => mb_substr(Request::str('last_name'), 0, 80),
            'phone' => Request::str('phone') ?: null,
            'signature' => Html::clean((string)Request::post('signature', ''), 'signature'),
            'onvacation' => Request::bool('onvacation') ? 1 : 0,
            'reply_redirect' => Request::str('reply_redirect') === 'queue' ? 'queue' : 'ticket',
            'updated_at' => DB::now(),
        ];
        if (\Bakkbone\HelpCentre\Core\Schema::hasColumn(App::db(), 'staff', 'default_signature')) {
            $sig = Request::str('default_signature');
            $data['default_signature'] = in_array($sig, ['mine', 'dept', 'none'], true) ? $sig : null;
        }
        App::db()->update('staff', $data, 'id = ?', [$this->staff['id']]);
        $this->flash('success', __('Profile saved.'));
        $this->redirect('/panel/profile');
    }

    public function password(): void
    {
        if ($this->staff['password_hash'] && !Auth::verifyPassword('staff', $this->staff, (string)Request::post('current_password', ''))) {
            $this->failWith(__('Your current password is incorrect.'), '/panel/profile');
        }
        $pw = (string)Request::post('password', '');
        if ($err = Auth::passwordError($pw, (string)Request::post('password_confirm', ''))) {
            $this->failWith($err, '/panel/profile');
        }
        App::db()->update('staff', ['password_hash' => Auth::hash($pw), 'updated_at' => DB::now()], 'id = ?', [$this->staff['id']]);
        Session::regenerate();
        Auth::refreshStamp('staff', (int)$this->staff['id']);
        Audit::log('staff.password_change', 'staff', (int)$this->staff['id']);
        $this->flash('success', __('Password changed.'));
        $this->redirect('/panel/profile');
    }

    public function twoFactorStart(): void
    {
        Session::set('totp_setup', Totp::generateSecret());
        $this->redirect('/panel/profile#two-factor');
    }

    public function twoFactorEnable(): void
    {
        $secret = (string)Session::get('totp_setup', '');
        if ($secret === '') {
            $this->redirect('/panel/profile#two-factor');
        }
        $counter = Totp::verify($secret, Request::str('code'));
        if ($counter === null) {
            $this->failWith(__('That code did not match. Check your device\'s clock and try again.'), '/panel/profile#two-factor');
        }
        App::db()->update('staff', ['totp_secret' => Crypto::encrypt($secret), 'totp_enabled' => 1, 'totp_last_counter' => $counter, 'updated_at' => DB::now()], 'id = ?', [$this->staff['id']]);
        Session::forget('totp_setup');
        Audit::log('staff.2fa_enabled', 'staff', (int)$this->staff['id']);
        $this->flash('success', __('Two-factor authentication is now on.'));
        $this->redirect('/panel/profile#two-factor');
    }

    public function twoFactorDisable(): void
    {
        if (Settings::bool('staff_require_2fa')) {
            $this->failWith(__('Two-factor authentication is required by your administrator.'), '/panel/profile#two-factor');
        }
        $secret = (string)Crypto::decrypt($this->staff['totp_secret']);
        if (Totp::verify($secret, Request::str('code')) === null) {
            $this->failWith(__('Enter a current code from your authenticator app to turn 2FA off.'), '/panel/profile#two-factor');
        }
        App::db()->update('staff', ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_counter' => null], 'id = ?', [$this->staff['id']]);
        Audit::log('staff.2fa_disabled', 'staff', (int)$this->staff['id']);
        $this->flash('success', __('Two-factor authentication turned off.'));
        $this->redirect('/panel/profile#two-factor');
    }
}
