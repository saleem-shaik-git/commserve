<?php
/** CommServe lightweight i18n. Supported locales: en, es, fr. */
const SUPPORTED_LOCALES = ['en','es','fr'];
const LOCALE_LABELS = ['en'=>'English','es'=>'Español','fr'=>'Français'];

function current_locale(): string {
    $locale = $_SESSION['locale'] ?? null;
    if (!$locale && function_exists('auth_user') && ($u = auth_user())) $locale = $u['locale'] ?? null;
    if (!$locale) $locale = env('DEFAULT_LOCALE', 'en');
    return in_array($locale, SUPPORTED_LOCALES, true) ? $locale : 'en';
}

function translations(): array {
    static $data = null;
    if ($data !== null) return $data;
    $data = [
      'en'=>[
        'Dashboard'=>'Dashboard','Accounts'=>'Accounts','Transfers'=>'Transfers','Payments'=>'Payments','Cards'=>'Cards','Notifications'=>'Notifications','Security'=>'Security','Statements'=>'Statements','Settings'=>'Settings','Logout'=>'Logout','Login'=>'Login','Sign in'=>'Sign in','Language'=>'Language','Currency'=>'Currency','Save changes'=>'Save changes','Security Activity'=>'Security Activity','Change Password'=>'Change Password','Reports & Analytics'=>'Reports & Analytics','Executive Intelligence'=>'Executive Intelligence','Customers'=>'Customers','Transactions'=>'Transactions','Completed'=>'Completed','Pending'=>'Pending','Failed'=>'Failed','Balance'=>'Balance','Account'=>'Account','Date'=>'Date','Amount'=>'Amount','Status'=>'Status','Description'=>'Description','Transaction history'=>'Transaction history','Demo Banking Portal'=>'Demo Banking Portal','SIMULATION ONLY — NO REAL MONEY'=>'SIMULATION ONLY — NO REAL MONEY','English'=>'English','Español'=>'Español','Français'=>'Français'
      ],
      'es'=>[
        'Dashboard'=>'Panel','Accounts'=>'Cuentas','Transfers'=>'Transferencias','Payments'=>'Pagos','Cards'=>'Tarjetas','Notifications'=>'Notificaciones','Security'=>'Seguridad','Statements'=>'Estados de cuenta','Settings'=>'Configuración','Logout'=>'Cerrar sesión','Login'=>'Iniciar sesión','Sign in'=>'Iniciar sesión','Language'=>'Idioma','Currency'=>'Moneda','Save changes'=>'Guardar cambios','Security Activity'=>'Actividad de seguridad','Change Password'=>'Cambiar contraseña','Reports & Analytics'=>'Informes y análisis','Executive Intelligence'=>'Inteligencia ejecutiva','Customers'=>'Clientes','Transactions'=>'Transacciones','Completed'=>'Completadas','Pending'=>'Pendientes','Failed'=>'Fallidas','Balance'=>'Saldo','Account'=>'Cuenta','Date'=>'Fecha','Amount'=>'Importe','Status'=>'Estado','Description'=>'Descripción','Transaction history'=>'Historial de transacciones','Demo Banking Portal'=>'Portal bancario de demostración','SIMULATION ONLY — NO REAL MONEY'=>'SIMULACIÓN — SIN DINERO REAL','English'=>'English','Español'=>'Español','Français'=>'Français'
      ],
      'fr'=>[
        'Dashboard'=>'Tableau de bord','Accounts'=>'Comptes','Transfers'=>'Virements','Payments'=>'Paiements','Cards'=>'Cartes','Notifications'=>'Notifications','Security'=>'Sécurité','Statements'=>'Relevés','Settings'=>'Paramètres','Logout'=>'Déconnexion','Login'=>'Connexion','Sign in'=>'Se connecter','Language'=>'Langue','Currency'=>'Devise','Save changes'=>'Enregistrer','Security Activity'=>'Activité de sécurité','Change Password'=>'Changer le mot de passe','Reports & Analytics'=>'Rapports et analyses','Executive Intelligence'=>'Intelligence exécutive','Customers'=>'Clients','Transactions'=>'Transactions','Completed'=>'Terminées','Pending'=>'En attente','Failed'=>'Échouées','Balance'=>'Solde','Account'=>'Compte','Date'=>'Date','Amount'=>'Montant','Status'=>'Statut','Description'=>'Description','Transaction history'=>'Historique des transactions','Demo Banking Portal'=>'Portail bancaire de démonstration','SIMULATION ONLY — NO REAL MONEY'=>'SIMULATION — AUCUN ARGENT RÉEL','English'=>'English','Español'=>'Español','Français'=>'Français'
      ]
    ];
    return $data;
}
function t(string $key, ?string $fallback=null): string { $locale=current_locale(); return translations()[$locale][$key] ?? translations()['en'][$key] ?? ($fallback ?? $key); }
function set_locale(string $locale): void { if (!in_array($locale,SUPPORTED_LOCALES,true)) $locale='en'; $_SESSION['locale']=$locale; }
function language_selector(string $class='form-select form-select-sm'): string { $html='<form method="post" action="/commserve/public/language.php" class="d-inline-flex align-items-center gap-2">'.csrf_field().'<select name="locale" class="'.e($class).'" onchange="this.form.submit()" aria-label="'.e(t('Language')).'">'; foreach(LOCALE_LABELS as $code=>$label) $html.='<option value="'.e($code).'"'.(current_locale()===$code?' selected':'').'>'.e($label).'</option>'; return $html.'</select></form>'; }
