<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shortcodes for the public catalog (with in-place editor UI) and the login form.
 */
class KM_Shortcodes
{

    public static function register()
    {
        add_shortcode('kniznica_katalog', array(__CLASS__, 'render_catalog'));
        add_shortcode('kniznica_prihlasenie', array(__CLASS__, 'render_login'));
    }

    private static function enqueue_assets()
    {
        wp_enqueue_style('km-google-fonts', 'https://fonts.googleapis.com/css2?family=Fira+Sans:wght@400;500;600;700&display=swap', array(), null);
        wp_enqueue_style('km-public', KM_PLUGIN_URL . 'public/assets/css/km-public.css', array('km-google-fonts'), KM_VERSION);
        wp_enqueue_script('km-public', KM_PLUGIN_URL . 'public/assets/js/km-public.js', array(), KM_VERSION, true);

        $settings = KM_Settings::get_all();
        $current_user = wp_get_current_user();

        wp_localize_script('km-public', 'KM_DATA', array(
            'restUrl'       => esc_url_raw(rest_url('knizica-mamateyka/v1')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'loginNonce'    => wp_create_nonce('km_login'),
            'isLoggedIn'    => is_user_logged_in(),
            'isEditor'      => current_user_can('km_manage_books'),
            'editorName'    => $current_user->exists() ? $current_user->display_name : '',
            'gdprText'      => $settings['gdpr_text'],
            'introText'     => $settings['reservation_intro_text'],
            'defaultImage'  => KM_DB::DEFAULT_BOOK_IMAGE,
            'i18n'          => self::i18n_strings(),
        ));
    }

    private static function i18n_strings()
    {
        return array(
            'search'            => __('Hľadať podľa názvu, autora...', 'knizica-mamateyka'),
            'allCategories'     => __('Všetky kategórie', 'knizica-mamateyka'),
            'anyAvailability'   => __('Všetky knihy', 'knizica-mamateyka'),
            'availableOnly'     => __('Len dostupné', 'knizica-mamateyka'),
            'uncheckedOnly'     => __('Len neskontrolované', 'knizica-mamateyka'),
            'checkedOnly'       => __('Len skontrolované', 'knizica-mamateyka'),
            'reviewFilter'      => __('Kontrola kníh', 'knizica-mamateyka'),
            'lastCheckedAt'     => __('Naposledy skontrolované', 'knizica-mamateyka'),
            'copiesAvailable'   => __('Dostupné kópie', 'knizica-mamateyka'),
            'reserve'           => __('Rezervovať', 'knizica-mamateyka'),
            'close'             => __('Späť do katalógu', 'knizica-mamateyka'),
            'yourName'          => __('Vaše meno', 'knizica-mamateyka'),
            'yourEmail'         => __('Váš email', 'knizica-mamateyka'),
            'yourPhone'         => __('Vaše telefónne číslo', 'knizica-mamateyka'),
            'errNameRequired'   => __('Zadajte svoje meno.', 'knizica-mamateyka'),
            'errNameShort'      => __('Meno je príliš krátke.', 'knizica-mamateyka'),
            'errEmailRequired'  => __('Zadajte svoj email.', 'knizica-mamateyka'),
            'errEmailInvalid'   => __('Zadajte platný email, napríklad jana@example.sk.', 'knizica-mamateyka'),
            'errPhoneRequired'  => __('Zadajte telefónne číslo.', 'knizica-mamateyka'),
            'errPhoneInvalid'   => __('Zadajte platné telefónne číslo (9 až 15 číslic), napríklad +421 900 123 456.', 'knizica-mamateyka'),
            'errConsentRequired' => __('Pre odoslanie je potrebný súhlas so spracovaním osobných údajov.', 'knizica-mamateyka'),
            'send'              => __('Odoslať rezerváciu', 'knizica-mamateyka'),
            'reservationSent'   => __('Rezervácia bola odoslaná. Ozveme sa vám emailom.', 'knizica-mamateyka'),
            'addBook'           => __('Pridať knihu', 'knizica-mamateyka'),
            'edit'              => __('Upraviť', 'knizica-mamateyka'),
            'delete'            => __('Presunúť do koša', 'knizica-mamateyka'),
            'history'           => __('História', 'knizica-mamateyka'),
            'historyLoading'    => __('Načítavam históriu...', 'knizica-mamateyka'),
            'historyEmpty'      => __('Táto kniha zatiaľ nemá zaznamenanú históriu.', 'knizica-mamateyka'),
            'historyChanges'    => __('urobil zmeny', 'knizica-mamateyka'),
            'coverUpload'       => __('Nahrať obálku', 'knizica-mamateyka'),
            'coverReplace'      => __('Nahradiť obálku', 'knizica-mamateyka'),
            'coverCamera'       => __('Odfotiť obálku', 'knizica-mamateyka'),
            'coverRemove'       => __('Odstrániť obálku', 'knizica-mamateyka'),
            'coverUploading'    => __('Nahrávam...', 'knizica-mamateyka'),
            'coverConfirmRemove' => __('Odstrániť obálku tejto knihy?', 'knizica-mamateyka'),
            'coverEdit'         => __('Upraviť obálku', 'knizica-mamateyka'),
            'coverDialogTitle'  => __('Obálka knihy', 'knizica-mamateyka'),
            'coverRestore'      => __('Obnoviť zmazanú obálku', 'knizica-mamateyka'),
            'coverRestoreTitle' => __('Obnoviť obálku', 'knizica-mamateyka'),
            'coverRestoreNone'  => __('Žiadna z predchádzajúcich obálok už nie je dostupná.', 'knizica-mamateyka'),
            'coverUnavailable'  => __('už nie je dostupná', 'knizica-mamateyka'),
            'coverWasRemoved'   => __('odstránená', 'knizica-mamateyka'),
            'coverWasReplaced'  => __('nahradená', 'knizica-mamateyka'),
            'coverDeleteFile'   => __('Natrvalo zmazať súbor', 'knizica-mamateyka'),
            'coverDeleteConfirm' => __('Natrvalo zmazať tento súbor z WordPressu? Nedá sa to vrátiť.', 'knizica-mamateyka'),
            'coverDeleteAction' => __('Zmazať súbor', 'knizica-mamateyka'),
            'coverDeleteFailed' => __('Súbor sa nepodarilo zmazať.', 'knizica-mamateyka'),
            'back'              => __('Späť', 'knizica-mamateyka'),
            'coverUnreadable'   => __('Obrázok sa nepodarilo načítať. Použite JPG, PNG alebo WebP.', 'knizica-mamateyka'),
            'emptyValue'        => __('-', 'knizica-mamateyka'),
            'archive'           => __('Kôš', 'knizica-mamateyka'),
            'archived'          => __('V koši', 'knizica-mamateyka'),
            'restore'           => __('Obnoviť knihu', 'knizica-mamateyka'),
            'viewDetail'        => __('Zobraziť', 'knizica-mamateyka'),
            'permanentDelete'   => __('Trvalo odstrániť', 'knizica-mamateyka'),
            'archivedWarning'   => __('Táto kniha je v koši. Verejnosť ju nevidí.', 'knizica-mamateyka'),
            'confirmPermanentDelete' => __('Toto odstránenie je nevratné a vymaže aj celú históriu knihy. Naozaj chcete pokračovať?', 'knizica-mamateyka'),
            'noArchivedBooks'   => __('Kôš je prázdny.', 'knizica-mamateyka'),
            'deleteCategory'    => __('Zmazať', 'knizica-mamateyka'),
            'save'              => __('Uložiť', 'knizica-mamateyka'),
            'cancel'            => __('Zrušiť', 'knizica-mamateyka'),
            'confirmDelete'     => __('Presunúť túto knihu do koša?', 'knizica-mamateyka'),
            'confirmArchive'    => __('Presunúť túto knihu do koša?', 'knizica-mamateyka'),
            'reservations'      => __('Rezervácie', 'knizica-mamateyka'),
            'reservationPending' => __('Čaká na spracovanie', 'knizica-mamateyka'),
            'reservationAssigned' => __('Pridelená', 'knizica-mamateyka'),
            'reservationBorrowed' => __('Vypožičaná', 'knizica-mamateyka'),
            'reservationReturned' => __('Vrátená', 'knizica-mamateyka'),
            'reservationRejected' => __('Zamietnutá', 'knizica-mamateyka'),
            'allStatuses'       => __('Všetky stavy', 'knizica-mamateyka'),
            'reservationSearch' => __('Hľadať rezervácie', 'knizica-mamateyka'),
            'takeReservation'   => __('Prevziať rezerváciu', 'knizica-mamateyka'),
            'releaseReservation' => __('Uvoľniť', 'knizica-mamateyka'),
            'markBorrowed'      => __('Označiť ako vypožičanú', 'knizica-mamateyka'),
            'markReturned'      => __('Označiť ako vrátenú', 'knizica-mamateyka'),
            'librarianName'     => __('Meno knihovníka', 'knizica-mamateyka'),
            'librarianRequired' => __('Zadajte meno knihovníka.', 'knizica-mamateyka'),
            'reservationNote'   => __('Interná poznámka', 'knizica-mamateyka'),
            'rejectReservation' => __('Zamietnuť rezerváciu', 'knizica-mamateyka'),
            'reservationNoResults' => __('Pre tento filter sa nenašli žiadne rezervácie.', 'knizica-mamateyka'),
            'reservationSaved'  => __('Stav rezervácie bol zmenený.', 'knizica-mamateyka'),
            'noResults'         => __('Nenašli sa žiadne knihy.', 'knizica-mamateyka'),
            'loadMore'          => __('Načítať ďalšie', 'knizica-mamateyka'),
            'gridView'          => __('Mriežka', 'knizica-mamateyka'),
            'listView'          => __('Zoznam', 'knizica-mamateyka'),
            'loggedInAs'        => __('Prihlásená ako', 'knizica-mamateyka'),
            'loggedInAsEditor'  => __('Prihlásený ako editor', 'knizica-mamateyka'),
            'logout'            => __('Odhlásiť sa', 'knizica-mamateyka'),
            'username'          => __('Meno', 'knizica-mamateyka'),
            'password'          => __('Heslo', 'knizica-mamateyka'),
            'login'             => __('Prihlásiť sa', 'knizica-mamateyka'),
            'loginFailed'       => __('Prihlásenie zlyhalo.', 'knizica-mamateyka'),
            'total'             => __('Celkovo kópií', 'knizica-mamateyka'),
            'bookId'            => __('Číslo knihy', 'knizica-mamateyka'),
            'categories'        => __('Kategórie', 'knizica-mamateyka'),
            'categoriesTitle'   => __('Správa kategórií', 'knizica-mamateyka'),
            'backToCatalog'     => __('Späť do katalógu', 'knizica-mamateyka'),
            'addNew'            => __('Pridať', 'knizica-mamateyka'),
            'categoryAdded'     => __('Kategória bola pridaná.', 'knizica-mamateyka'),
            'namePlaceholder'   => __('Názov', 'knizica-mamateyka'),
            'categoryCode'      => __('Skratka', 'knizica-mamateyka'),
            'categoryCodeRequired' => __('Zadajte kód kategórie.', 'knizica-mamateyka'),
            'categoryCodeLength' => __('Kód kategórie musí mať 2 až 20 znakov.', 'knizica-mamateyka'),
            'categoryCodeFormat' => __('Kód môže obsahovať iba veľké písmená a číslice.', 'knizica-mamateyka'),
            'categoryColorInvalid' => __('Farba kategórie musí byť v tvare #RRGGBB.', 'knizica-mamateyka'),
            'confirmDeleteCategory' => __('Naozaj chcete odstrániť túto kategóriu?', 'knizica-mamateyka'),
            'allYears'          => __('Všetky roky', 'knizica-mamateyka'),
            'clearFilters'      => __('Zrušiť filter', 'knizica-mamateyka'),
            'noAccess'          => __('Táto stránka je dostupná len pre prihlásených editorov.', 'knizica-mamateyka'),
        );
    }

    public static function render_catalog($atts)
    {
        self::enqueue_assets();
        return '<div id="km-catalog-root" class="km-root"></div>';
    }

    public static function render_login($atts)
    {
        self::enqueue_assets();

        if (is_user_logged_in() && current_user_can('km_manage_books')) {
            $user = wp_get_current_user();
            return '<div id="km-login-root" class="km-root" data-logged-in="1">'
                . '<p>' . esc_html__('Prihlásená ako', 'knizica-mamateyka') . ' ' . esc_html($user->display_name) . '</p>'
                . '</div>';
        }

        return '<div id="km-login-root" class="km-root"></div>';
    }
}
