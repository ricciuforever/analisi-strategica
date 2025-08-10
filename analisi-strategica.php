<?php
/**
 * Plugin Name: Analisi Strategica Marketing
 * Plugin URI: https://tuosito.com/
 * Description: Un plugin per visualizzare i dati di marketing in una bacheca interattiva con grafici, accessibile da menu admin.
 * Version: 1.6
 * Author: Emanuele Tolomei & Jules
 * Author URI: https://tuosito.com/
 * License: GPL2
 */

// Evita accessi diretti al file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definisci i percorsi assoluti per le cartelle del plugin
define( 'AS_MARKETING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AS_MARKETING_CSV_DIR', AS_MARKETING_PLUGIN_DIR . 'csv/' );
define( 'AS_MARKETING_DATA_DIR', AS_MARKETING_PLUGIN_DIR . 'data/' );
define( 'AS_MARKETING_EXCEL_FILE_NAME', 'Commerciale.xlsx' );
define( 'AS_MARKETING_PYTHON_SCRIPT_PATH', AS_MARKETING_PLUGIN_DIR . 'generate_charts.py' );
define( 'AS_MARKETING_STRATEGIC_ANALYSIS_FILE', AS_MARKETING_DATA_DIR . 'strategic_analysis.txt' );


/**
 * Aggiunge la voce di menu all'area amministrativa di WordPress.
 */
function as_marketing_add_admin_menu() {
    add_menu_page(
        'Analisi Strategica',             // Titolo della pagina
        'Analisi Strategica',             // Testo del menu
        'manage_options',                 // Capacità richiesta per visualizzare il menu
        'analisi-strategica-dashboard',   // Slug del menu
        'as_marketing_dashboard_page',    // Funzione di callback che renderizza la pagina
        'dashicons-chart-bar',            // Icona del menu (puoi scegliere un'altra dashicon)
        6                                 // Posizione del menu (scegli un numero per la posizione desiderata)
    );
}
add_action( 'admin_menu', 'as_marketing_add_admin_menu' );

/**
 * Funzione per includere gli script e gli stili necessari SOLO nella pagina del plugin.
 */
function as_marketing_enqueue_admin_scripts($hook) {
    // Carica gli script e gli stili solo sulla pagina del nostro plugin
    if ( 'toplevel_page_analisi-strategica-dashboard' != $hook ) {
        return;
    }

    // Registra e accoda lo script Vega-Lite dalla CDN (necessario per renderizzare i JSON di Altair)
    wp_enqueue_script( 'vega', 'https://cdn.jsdelivr.net/npm/vega@5', array(), '5.29.0', true );
    wp_enqueue_script( 'vega-lite', 'https://cdn.jsdelivr.net/npm/vega-lite@5', array('vega'), '5.17.0', true );
    wp_enqueue_script( 'vega-embed', 'https://cdn.jsdelivr.net/npm/vega-embed@6', array('vega', 'vega-lite'), '6.22.1', true );

    // Registra e accoda il tuo script personalizzato
    wp_enqueue_script(
        'as-marketing-dashboard-js',
        plugins_url( 'js/dashboard.js', __FILE__ ),
        array('jquery', 'vega-embed'), // Dipendenze
        filemtime( plugin_dir_path( __FILE__ ) . 'js/dashboard.js' ), // Versione basata su timestamp file
        true // Carica nel footer
    );

    // Passa i dati da PHP a JavaScript in modo sicuro
    wp_localize_script(
        'as-marketing-dashboard-js',
        'asMarketing', // Nome dell'oggetto JavaScript che conterrà i dati
        array(
            'plugin_url' => plugins_url( '/', __FILE__ ) // Passa l'URL base del plugin
        )
    );

    // Accoda il tuo foglio di stile
    wp_enqueue_style(
        'as-marketing-dashboard-css',
        plugins_url( 'css/dashboard.css', __FILE__ ),
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'css/dashboard.css' ) // Versione basata su timestamp file
    );
}
add_action( 'admin_enqueue_scripts', 'as_marketing_enqueue_admin_scripts' );


/**
 * Funzione per gestire l'upload del file e l'esecuzione dello script Python.
 */
function as_marketing_handle_data_update() {
    // Controlla il nonce per la sicurezza
    if ( ! isset( $_POST['as_marketing_nonce'] ) || ! wp_verify_nonce( $_POST['as_marketing_nonce'], 'as_marketing_update_data_nonce' ) ) {
        // Aggiungi un messaggio di errore se il nonce non è valido
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_nonce_error',
            'Errore di sicurezza: ricarica la pagina e riprova.',
            'error'
        );
        return;
    }

    // Processa l'upload del file Excel
    if ( isset( $_FILES['excel_file'] ) && ! empty( $_FILES['excel_file']['name'] ) ) {
        $uploaded_file = $_FILES['excel_file'];

        // Definisci la directory di upload e i tipi di file consentiti
        // Usiamo un array vuoto per 'test_form' => false per permettere a wp_handle_upload di spostare il file
        $upload_overrides = array( 'test_form' => false );
        $movefile = wp_handle_upload( $uploaded_file, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $uploaded_path = $movefile['file'];
            $file_name = basename($uploaded_path);
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);

            // Validazione del nome e dell'estensione del file
            if ( $file_name === AS_MARKETING_EXCEL_FILE_NAME && in_array(strtolower($file_extension), array('xlsx', 'xls', 'csv')) ) {
                // Elimina il vecchio file se esiste
                $target_path = AS_MARKETING_CSV_DIR . AS_MARKETING_EXCEL_FILE_NAME;
                if ( file_exists( $target_path ) ) {
                    unlink( $target_path );
                }

                // Assicurati che la directory di destinazione esista
                if ( ! is_dir(AS_MARKETING_CSV_DIR) ) {
                    mkdir(AS_MARKETING_CSV_DIR, 0755, true);
                }

                // Sposta il nuovo file nella cartella csv del plugin
                if ( rename( $uploaded_path, $target_path ) ) {
                    add_settings_error(
                        'as_marketing_messages',
                        'as_marketing_upload_success',
                        'File Excel caricato con successo: ' . AS_MARKETING_EXCEL_FILE_NAME,
                        'success'
                    );

                    // Dopo l'upload e lo spostamento, esegui lo script Python
                    as_marketing_trigger_python_script();

                } else {
                    add_settings_error(
                        'as_marketing_messages',
                        'as_marketing_move_error',
                        'Errore nello spostamento del file caricato. Controlla i permessi della cartella ' . AS_MARKETING_CSV_DIR,
                        'error'
                    );
                    // Prova a eliminare il file temporaneo se lo spostamento fallisce
                    unlink($uploaded_path);
                }
            } else {
                add_settings_error(
                    'as_marketing_messages',
                    'as_marketing_file_name_error',
                    'Nome o estensione del file non validi. Carica un file chiamato "' . AS_MARKETING_EXCEL_FILE_NAME . '" (es. .xlsx, .xls, .csv).',
                    'error'
                );
                // Elimina il file caricato se non valido
                unlink($uploaded_path);
            }
        } else {
            add_settings_error(
                'as_marketing_messages',
                'as_marketing_upload_error',
                'Errore nel caricamento del file: ' . (isset($movefile['error']) ? $movefile['error'] : 'Errore sconosciuto.'),
                'error'
            );
        }
    } else {
        // Se non c'è un file in upload, ma il pulsante è stato cliccato, ri-triggera solo lo script Python
        if ( isset($_POST['as_marketing_update_data']) ) {
            as_marketing_trigger_python_script();
        } else {
             // Nessun file caricato e nessun trigger manuale, potrebbe essere un submit vuoto
             add_settings_error(
                'as_marketing_messages',
                'as_marketing_no_file_selected',
                'Nessun file selezionato per il caricamento.',
                'info'
            );
        }
    }

    // Reindirizza l'utente alla pagina del plugin per visualizzare i messaggi
    $redirect_url = admin_url('admin.php?page=analisi-strategica-dashboard');
    wp_redirect( $redirect_url );
    exit;
}
add_action( 'admin_post_as_marketing_update', 'as_marketing_handle_data_update' );
add_action( 'admin_post_nopriv_as_marketing_update', 'as_marketing_handle_data_update' );


/**
 * Funzione per eseguire lo script Python.
 */
function as_marketing_trigger_python_script() {
    $script_path = AS_MARKETING_PYTHON_SCRIPT_PATH;
    // Utilizziamo il percorso completo dell'interprete python3 che hai verificato funziona
    // Sostituisci questo percorso solo se 'python3' da solo non è sufficiente o se il tuo python3.9 non è in /usr/bin/python3
    $python_executable = '/usr/bin/python3.9'; // Usa python3.9 specificamente, come hai verificato.

    // --- INIZIO MODIFICA TEMPORANEA PER DEBUG AVANZATO ---
    // Questa configurazione catturerà l'output (stdout e stderr) in un file di log
    // per aiutarti a diagnosticare il problema quando il pulsante non fa nulla.
    $debug_log_file = AS_MARKETING_PLUGIN_DIR . 'shell_exec_debug.log';

    // Per sicurezza, pulisci il log precedente prima di scrivere il nuovo
    if (file_exists($debug_log_file)) {
        unlink($debug_log_file);
    }

    // Costruiamo il comando:
    // Esegui lo script Python direttamente, reindirizzando tutto l'output (stdout e stderr) al file di log.
    // NON usiamo 'nohup' o '&' in questa fase di debug, perché vogliamo che PHP aspetti l'esecuzione e catturi l'output.
    $command_to_execute = sprintf(
        '%s %s > %s 2>&1', // Esegui e reindirizza tutto l'output al file di debug
        escapeshellarg($python_executable),
        escapeshellarg($script_path),
        escapeshellarg($debug_log_file)
    );

    // Verifica che la funzione shell_exec sia disponibile (già presente, mantenuta per sicurezza)
    if ( ! function_exists('shell_exec') || ! is_callable('shell_exec') ) {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_shell_exec_disabled',
            'Errore: La funzione shell_exec() è disabilitata sul tuo server. Contatta il tuo hosting.',
            'error'
        );
        return;
    }

    // Assicurati che lo script Python abbia i permessi di esecuzione (già presente, mantenuta per sicurezza)
    if ( ! is_executable($script_path) ) {
        @chmod($script_path, 0755); // Tentativo di impostare i permessi
        if ( ! is_executable($script_path) ) {
            add_settings_error(
                'as_marketing_messages',
                'as_marketing_script_permissions',
                'Errore: Lo script Python non ha i permessi di esecuzione. Assicurati che sia 755.',
                'error'
            );
            return;
        }
    }

    // Esegui il comando
    // Questo catturerà l'output che il comando produce, ma l'output completo sarà nel file di log
    $output_from_shell_exec = shell_exec($command_to_execute); // Potrebbe essere vuoto a causa del reindirizzamento

    // Leggi il contenuto del log dopo l'esecuzione
    $log_content = file_exists($debug_log_file) ? file_get_contents($debug_log_file) : "Log file non trovato o vuoto.";

    // Controlla il log per errori o messaggi chiave
    // Se il log contiene "Errore" o "Traceback" o se è vuoto (ma lo script dovrebbe stampare "Inizio..."), segnala un errore.
    if (empty($log_content) || strpos($log_content, "Errore") !== false || strpos($log_content, "Traceback") !== false) {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_script_failed',
            'Errore rilevato nell\'esecuzione dello script Python. Controlla il log di debug: <code>' . esc_html(basename($debug_log_file)) . '</code><br> Contenuto log: <pre>' . esc_html($log_content) . '</pre><br> Comando eseguito: <pre>' . esc_html($command_to_execute) . '</pre>',
            'error'
        );
    } else {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_script_started',
            'Comando shell_exec avviato. Controlla il log di debug: <code>' . esc_html(basename($debug_log_file)) . '</code><br> Ultimi 500 caratteri del log: <pre>' . esc_html(substr($log_content, -500)) . '</pre>',
            'success'
        );
    }
    // --- FINE MODIFICA TEMPORANEA PER DEBUG AVANZATO ---

    // La funzione chiamante (as_marketing_handle_data_update) gestisce già il redirect finale.
}


/**
 * Funzione di callback che renderizza il contenuto della pagina della bacheca.
 */
function as_marketing_dashboard_page() {
    // Leggi il contenuto del file di analisi strategica
    $strategic_analysis_content = 'Nessuna analisi strategica disponibile. Esegui l\'aggiornamento dati.';
    if ( file_exists( AS_MARKETING_STRATEGIC_ANALYSIS_FILE ) ) {
        $strategic_analysis_content = file_get_contents( AS_MARKETING_STRATEGIC_ANALYSIS_FILE );
        if ( empty( $strategic_analysis_content ) ) {
            $strategic_analysis_content = 'Il file di analisi strategica è vuoto. Prova a eseguire nuovamente l\'aggiornamento dati.';
        }
    }

    ?>
    <div class="wrap as-marketing-dashboard">
        <h1>Bacheca di Analisi Strategica Marketing</h1>

        <?php settings_errors( 'as_marketing_messages' ); // Mostra i messaggi di successo/errore ?>

        <div class="dashboard-section dashboard-info-box">
            <p><strong>Gestione Dati:</strong> Carica il tuo file Excel `<?php echo AS_MARKETING_EXCEL_FILE_NAME; ?>` più recente qui sotto. Una volta caricato, lo script di analisi Python verrà automaticamente avviato per aggiornare i grafici e generare la nuova analisi strategica.</p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="as_marketing_update">
                <?php wp_nonce_field( 'as_marketing_update_data_nonce', 'as_marketing_nonce' ); ?>

                <p>
                    <label for="excel_file">Seleziona il file Excel (formato .xlsx, .xls, .csv):</label><br>
                    <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </p>
                <p>
                    <input type="submit" name="as_marketing_upload_and_update" class="button button-primary" value="Carica e Aggiorna Grafici & Analisi">
                </p>
            </form>

            <p>
                Oppure, se il file `<?php echo AS_MARKETING_EXCEL_FILE_NAME; ?>` è già nella cartella `/csv/` e vuoi solo rigenerare i grafici e l'analisi strategica:<br>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <input type="hidden" name="action" value="as_marketing_update">
                    <input type="hidden" name="as_marketing_update_data" value="1">
                    <?php wp_nonce_field( 'as_marketing_update_data_nonce', 'as_marketing_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="Rigenera Grafici & Analisi">
                </form>
            </p>

            <p class="warning-text">
                <small><strong>Attenzione:</strong> L'aggiornamento dei grafici e dell'analisi strategica esegue uno script Python sul server. Questo processo potrebbe impiegare del tempo a seconda della dimensione dei dati e della complessità dell'analisi LLM. Se il tuo hosting limita l'esecuzione di comandi shell, o non ha `python3` nel PATH, questa funzione potrebbe non funzionare correttamente. È consigliato monitorare i log del server per verificare l'esito dell'esecuzione.</small>
            </p>
        </div>

        <div class="dashboard-section">
            <h2>Analisi Strategica Generale</h2>
            <div class="strategic-analysis-content">
                <?php echo nl2br( esc_html( $strategic_analysis_content ) ); ?>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-section">
                <h2>Panoramica Franchising</h2>

                <div class="chart-block">
                    <h3>1. Distribuzione Stato Lead - Franchising</h3>
                    <p class="chart-analysis">Questo grafico a torta mostra la percentuale di lead per il franchising che hanno raggiunto lo stato di 'Chiuso' (ovvero, un preventivo è stato emesso) rispetto a quelli che sono ancora nello stato 'Non Chiuso' (informazioni iniziali, non risponde, ecc.). Un tasso di conversione elevato indica un processo di vendita efficace dal contatto iniziale fino alla proposta commerciale. Monitorare questa metrica è fondamentale per valutare l'efficienza del tuo funnel di acquisizione lead.</p>
                    <div class="chart-container" id="chart-franchising-lead-status"></div>
                </div>

                <div class="chart-block">
                    <h3>2. Lead per Sorgente - Franchising</h3>
                    <p class="chart-analysis">Questo grafico a barre evidenzia le sorgenti principali da cui provengono i tuoi lead di franchising (es. 'Sito', 'LF', 'Diretto'). Identificare i canali più performanti ti permette di ottimizzare l'allocazione del budget marketing, investendo maggiormente nelle fonti che generano lead di qualità superiore e con maggiore probabilità di conversione. È utile anche per capire quali canali potrebbero necessitare di miglioramenti.</p>
                    <div class="chart-container" id="chart-franchising-leads-source"></div>
                </div>

                <div class="chart-block">
                    <h3>3. Volume Lead Mensile - Franchising</h3>
                    <p class="chart-analysis">Il grafico a linee 'Volume Lead Mensile' traccia l'andamento del numero di nuovi lead di franchising acquisiti ogni mese. Questa visualizzazione è cruciale per identificare tendenze stagionali, l'impatto di campagne marketing specifiche o eventi esterni. Un aumento costante indica una crescita sana nella generazione di opportunità, mentre cali improvvisi possono segnalare problemi nel funnel di acquisizione o inefficacia delle attività promozionali.</p>
                    <div class="chart-container" id="chart-franchising-monthly-leads"></div>
                </div>

                <div class="chart-block">
                    <h3>4. Top 10 Città per Volume Lead - Franchising</h3>
                    <p class="chart-analysis">Questo grafico mostra le dieci città che generano il maggior numero di lead per il franchising. Comprendere la distribuzione geografica dei tuoi lead ti permette di affinare le strategie di marketing localizzate, identificare mercati promettenti per l'espansione e personalizzare la comunicazione in base alle specificità territoriali. Concentrati sulle città con alto volume e potenziale di conversione.</p>
                    <div class="chart-container" id="chart-franchising-top-cities"></div>
                </div>
            </div>

            <div class="dashboard-section">
                <h2>Panoramica Prodotti</h2>

                <div class="chart-block">
                    <h3>1. Distribuzione Stato Lead - Prodotti</h3>
                    <p class="chart-analysis">Simile al franchising, questo grafico a torta per i prodotti indica la percentuale di lead che sono stati 'Chiusi' (ovvero, è stato effettuato un ordine o si è conclusa positivamente) rispetto a quelli 'Non Chiusi'. Questo ti dà una visione immediata della performance del tuo processo di vendita per i prodotti specifici, evidenziando quanto efficacemente i lead si trasformano in clienti paganti. Un basso tasso di chiusura potrebbe indicare problemi nella proposta di valore o nel follow-up.</p>
                    <div class="chart-container" id="chart-prodotti-lead-status"></div>
                </div>

                <div class="chart-block">
                    <h3>2. Lead per Sorgente - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a barre illustra le diverse sorgenti di acquisizione lead per i prodotti. Analizzare quali canali (es. 'Sito', 'Diretto') portano più lead è fondamentale per ottimizzare gli investimenti. Un canale che porta molti lead ma con basso tasso di chiusura potrebbe richiedere una revisione della qualità dei lead generati, mentre canali con meno volume ma alta conversione meritano più attenzione.</p>
                    <div class="chart-container" id="chart-prodotti-leads-source"></div>
                </div>

                <div class="chart-block">
                    <h3>3. Volume Lead Mensile - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a linee mostra il numero di lead di prodotto acquisiti ogni mese. L'analisi di questo trend ti aiuta a capire la risposta del mercato alle tue offerte di prodotti, l'efficacia delle promozioni e l'impatto di eventi esterni. È un indicatore chiave della domanda e della salute delle tue attività di lead generation per i prodotti.</p>
                    <div class="chart-container" id="chart-prodotti-monthly-leads"></div>
                </div>

                <div class="chart-block">
                    <h3>4. Top 10 Città per Volume Lead - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a barre identifica le dieci città da cui provengono la maggior parte dei lead di prodotto. Questa informazione è preziosa per strategie di marketing geo-mirate, campagne pubblicitarie locali o per l'identificazione di aree ad alto potenziale di crescita per la vendita dei prodotti. Potrebbe anche suggerire l'opportunità di eventi o partnership locali.</p>
                    <div class="chart-container" id="chart-prodotti-top-cities"></div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
<?php
/**
 * Plugin Name: Analisi Strategica Marketing
 * Plugin URI: https://tuosito.com/
 * Description: Un plugin per visualizzare i dati di marketing in una bacheca interattiva con grafici, accessibile da menu admin.
 * Version: 1.5
 * Author: Emanuele Tolomei
 * Author URI: https://tuosito.com/
 * License: GPL2
 */

// Evita accessi diretti al file
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definisci i percorsi assoluti per le cartelle del plugin
define( 'AS_MARKETING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AS_MARKETING_CSV_DIR', AS_MARKETING_PLUGIN_DIR . 'csv/' );
define( 'AS_MARKETING_DATA_DIR', AS_MARKETING_PLUGIN_DIR . 'data/' );
define( 'AS_MARKETING_EXCEL_FILE_NAME', 'Commerciale.xlsx' );
define( 'AS_MARKETING_PYTHON_SCRIPT_PATH', AS_MARKETING_PLUGIN_DIR . 'generate_charts.py' );
define( 'AS_MARKETING_STRATEGIC_ANALYSIS_FILE', AS_MARKETING_DATA_DIR . 'strategic_analysis.txt' );


/**
 * Aggiunge la voce di menu all'area amministrativa di WordPress.
 */
function as_marketing_add_admin_menu() {
    add_menu_page(
        'Analisi Strategica',             // Titolo della pagina
        'Analisi Strategica',             // Testo del menu
        'manage_options',                 // Capacità richiesta per visualizzare il menu
        'analisi-strategica-dashboard',   // Slug del menu
        'as_marketing_dashboard_page',    // Funzione di callback che renderizza la pagina
        'dashicons-chart-bar',            // Icona del menu (puoi scegliere un'altra dashicon)
        6                                 // Posizione del menu (scegli un numero per la posizione desiderata)
    );
}
add_action( 'admin_menu', 'as_marketing_add_admin_menu' );

/**
 * Funzione per includere gli script e gli stili necessari SOLO nella pagina del plugin.
 */
function as_marketing_enqueue_admin_scripts($hook) {
    // Carica gli script e gli stili solo sulla pagina del nostro plugin
    if ( 'toplevel_page_analisi-strategica-dashboard' != $hook ) {
        return;
    }

    // Registra e accoda lo script Vega-Lite dalla CDN (necessario per renderizzare i JSON di Altair)
    wp_enqueue_script( 'vega', 'https://cdn.jsdelivr.net/npm/vega@5', array(), '5.29.0', true );
    wp_enqueue_script( 'vega-lite', 'https://cdn.jsdelivr.net/npm/vega-lite@5', array('vega'), '5.17.0', true );
    wp_enqueue_script( 'vega-embed', 'https://cdn.jsdelivr.net/npm/vega-embed@6', array('vega', 'vega-lite'), '6.22.1', true );

    // Registra e accoda il tuo script personalizzato
    wp_enqueue_script(
        'as-marketing-dashboard-js',
        plugins_url( 'js/dashboard.js', __FILE__ ),
        array('jquery', 'vega-embed'), // Dipendenze
        filemtime( plugin_dir_path( __FILE__ ) . 'js/dashboard.js' ), // Versione basata su timestamp file
        true // Carica nel footer
    );

    // Passa i dati da PHP a JavaScript in modo sicuro
    wp_localize_script(
        'as-marketing-dashboard-js',
        'asMarketing', // Nome dell'oggetto JavaScript che conterrà i dati
        array(
            'plugin_url' => plugins_url( '/', __FILE__ ) // Passa l'URL base del plugin
        )
    );

    // Accoda il tuo foglio di stile
    wp_enqueue_style(
        'as-marketing-dashboard-css',
        plugins_url( 'css/dashboard.css', __FILE__ ),
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'css/dashboard.css' ) // Versione basata su timestamp file
    );
}
add_action( 'admin_enqueue_scripts', 'as_marketing_enqueue_admin_scripts' );

/**
 * Funzione per gestire l'upload del file e l'esecuzione dello script Python.
 */
function as_marketing_handle_data_update() {
    // Controlla il nonce per la sicurezza
    if ( ! isset( $_POST['as_marketing_nonce'] ) || ! wp_verify_nonce( $_POST['as_marketing_nonce'], 'as_marketing_update_data_nonce' ) ) {
        // Aggiungi un messaggio di errore se il nonce non è valido
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_nonce_error',
            'Errore di sicurezza: ricarica la pagina e riprova.',
            'error'
        );
        return;
    }

    // Processa l'upload del file Excel
    if ( isset( $_FILES['excel_file'] ) && ! empty( $_FILES['excel_file']['name'] ) ) {
        $uploaded_file = $_FILES['excel_file'];

        // Definisci la directory di upload e i tipi di file consentiti
        // Usiamo un array vuoto per 'test_form' => false per permettere a wp_handle_upload di spostare il file
        $upload_overrides = array( 'test_form' => false );
        $movefile = wp_handle_upload( $uploaded_file, $upload_overrides );

        if ( $movefile && ! isset( $movefile['error'] ) ) {
            $uploaded_path = $movefile['file'];
            $file_name = basename($uploaded_path);
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);

            // Validazione del nome e dell'estensione del file
            if ( $file_name === AS_MARKETING_EXCEL_FILE_NAME && in_array(strtolower($file_extension), array('xlsx', 'xls', 'csv')) ) {
                // Elimina il vecchio file se esiste
                $target_path = AS_MARKETING_CSV_DIR . AS_MARKETING_EXCEL_FILE_NAME;
                if ( file_exists( $target_path ) ) {
                    unlink( $target_path );
                }

                // Assicurati che la directory di destinazione esista
                if ( ! is_dir(AS_MARKETING_CSV_DIR) ) {
                    mkdir(AS_MARKETING_CSV_DIR, 0755, true);
                }

                // Sposta il nuovo file nella cartella csv del plugin
                if ( rename( $uploaded_path, $target_path ) ) {
                    add_settings_error(
                        'as_marketing_messages',
                        'as_marketing_upload_success',
                        'File Excel caricato con successo: ' . AS_MARKETING_EXCEL_FILE_NAME,
                        'success'
                    );

                    // Dopo l'upload e lo spostamento, esegui lo script Python
                    as_marketing_trigger_python_script();

                } else {
                    add_settings_error(
                        'as_marketing_messages',
                        'as_marketing_move_error',
                        'Errore nello spostamento del file caricato. Controlla i permessi della cartella ' . AS_MARKETING_CSV_DIR,
                        'error'
                    );
                    // Prova a eliminare il file temporaneo se lo spostamento fallisce
                    unlink($uploaded_path);
                }
            } else {
                add_settings_error(
                    'as_marketing_messages',
                    'as_marketing_file_name_error',
                    'Nome o estensione del file non validi. Carica un file chiamato "' . AS_MARKETING_EXCEL_FILE_NAME . '" (es. .xlsx, .xls, .csv).',
                    'error'
                );
                // Elimina il file caricato se non valido
                unlink($uploaded_path);
            }
        } else {
            add_settings_error(
                'as_marketing_messages',
                'as_marketing_upload_error',
                'Errore nel caricamento del file: ' . (isset($movefile['error']) ? $movefile['error'] : 'Errore sconosciuto.'),
                'error'
            );
        }
    } else {
        // Se non c'è un file in upload, ma il pulsante è stato cliccato, ri-triggera solo lo script Python
        if ( isset($_POST['as_marketing_update_data']) ) {
            as_marketing_trigger_python_script();
        } else {
             // Nessun file caricato e nessun trigger manuale, potrebbe essere un submit vuoto
             add_settings_error(
                'as_marketing_messages',
                'as_marketing_no_file_selected',
                'Nessun file selezionato per il caricamento.',
                'info'
            );
        }
    }

    // Reindirizza l'utente alla pagina del plugin per visualizzare i messaggi
    $redirect_url = admin_url('admin.php?page=analisi-strategica-dashboard');
    wp_redirect( $redirect_url );
    exit;
}
add_action( 'admin_post_as_marketing_update', 'as_marketing_handle_data_update' );
add_action( 'admin_post_nopriv_as_marketing_update', 'as_marketing_handle_data_update' );


/**
 * Funzione per eseguire lo script Python.
 */
function as_marketing_trigger_python_script() {
    $script_path = AS_MARKETING_PYTHON_SCRIPT_PATH;
    // Utilizziamo il percorso completo dell'interprete python3 che hai verificato funziona
    // Sostituisci questo percorso solo se 'python3' da solo non è sufficiente o se il tuo python3.9 non è in /usr/bin/python3
    $python_executable = '/usr/bin/python3.9'; // Usa python3.9 specificamente, come hai verificato.

    // --- INIZIO MODIFICA TEMPORANEA PER DEBUG AVANZATO ---
    // Questa configurazione catturerà l'output (stdout e stderr) in un file di log
    // per aiutarti a diagnosticare il problema quando il pulsante non fa nulla.
    $debug_log_file = AS_MARKETING_PLUGIN_DIR . 'shell_exec_debug.log';

    // Per sicurezza, pulisci il log precedente prima di scrivere il nuovo
    if (file_exists($debug_log_file)) {
        unlink($debug_log_file);
    }

    // Costruiamo il comando:
    // Esegui lo script Python direttamente, reindirizzando tutto l'output (stdout e stderr) al file di log.
    // NON usiamo 'nohup' o '&' in questa fase di debug, perché vogliamo che PHP aspetti l'esecuzione e catturi l'output.
    $command_to_execute = sprintf(
        '%s %s > %s 2>&1', // Esegui e reindirizza tutto l'output al file di debug
        escapeshellarg($python_executable),
        escapeshellarg($script_path),
        escapeshellarg($debug_log_file)
    );

    // Verifica che la funzione shell_exec sia disponibile (già presente, mantenuta per sicurezza)
    if ( ! function_exists('shell_exec') || ! is_callable('shell_exec') ) {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_shell_exec_disabled',
            'Errore: La funzione shell_exec() è disabilitata sul tuo server. Contatta il tuo hosting.',
            'error'
        );
        return;
    }

    // Assicurati che lo script Python abbia i permessi di esecuzione (già presente, mantenuta per sicurezza)
    if ( ! is_executable($script_path) ) {
        @chmod($script_path, 0755); // Tentativo di impostare i permessi
        if ( ! is_executable($script_path) ) {
            add_settings_error(
                'as_marketing_messages',
                'as_marketing_script_permissions',
                'Errore: Lo script Python non ha i permessi di esecuzione. Assicurati che sia 755.',
                'error'
            );
            return;
        }
    }

    // Esegui il comando
    // Questo catturerà l'output che il comando produce, ma l'output completo sarà nel file di log
    $output_from_shell_exec = shell_exec($command_to_execute); // Potrebbe essere vuoto a causa del reindirizzamento

    // Leggi il contenuto del log dopo l'esecuzione
    $log_content = file_exists($debug_log_file) ? file_get_contents($debug_log_file) : "Log file non trovato o vuoto.";

    // Controlla il log per errori o messaggi chiave
    // Se il log contiene "Errore" o "Traceback" o se è vuoto (ma lo script dovrebbe stampare "Inizio..."), segnala un errore.
    if (empty($log_content) || strpos($log_content, "Errore") !== false || strpos($log_content, "Traceback") !== false) {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_script_failed',
            'Errore rilevato nell\'esecuzione dello script Python. Controlla il log di debug: <code>' . esc_html(basename($debug_log_file)) . '</code><br> Contenuto log: <pre>' . esc_html($log_content) . '</pre><br> Comando eseguito: <pre>' . esc_html($command_to_execute) . '</pre>',
            'error'
        );
    } else {
        add_settings_error(
            'as_marketing_messages',
            'as_marketing_script_started',
            'Comando shell_exec avviato. Controlla il log di debug: <code>' . esc_html(basename($debug_log_file)) . '</code><br> Ultimi 500 caratteri del log: <pre>' . esc_html(substr($log_content, -500)) . '</pre>',
            'success'
        );
    }
    // --- FINE MODIFICA TEMPORANEA PER DEBUG AVANZATO ---

    // La funzione chiamante (as_marketing_handle_data_update) gestisce già il redirect finale.
}


/**
 * Funzione di callback che renderizza il contenuto della pagina della bacheca.
 */
function as_marketing_dashboard_page() {
    // Leggi il contenuto del file di analisi strategica
    $strategic_analysis_content = 'Nessuna analisi strategica disponibile. Esegui l'aggiornamento dati.';
    if ( file_exists( AS_MARKETING_STRATEGIC_ANALYSIS_FILE ) ) {
        $strategic_analysis_content = file_get_contents( AS_MARKETING_STRATEGIC_ANALYSIS_FILE );
        if ( empty( $strategic_analysis_content ) ) {
            $strategic_analysis_content = 'Il file di analisi strategica è vuoto. Prova a eseguire nuovamente l'aggiornamento dati.';
        }
    }
    
    ?>
    <div class="wrap as-marketing-dashboard">
        <h1>Bacheca di Analisi Strategica Marketing</h1>

        <?php settings_errors( 'as_marketing_messages' ); // Mostra i messaggi di successo/errore ?>

        <div class="dashboard-section dashboard-info-box">
            <p><strong>Gestione Dati:</strong> Carica il tuo file Excel `<?php echo AS_MARKETING_EXCEL_FILE_NAME; ?>` più recente qui sotto. Una volta caricato, lo script di analisi Python verrà automaticamente avviato per aggiornare i grafici e generare la nuova analisi strategica.</p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="as_marketing_update">
                <?php wp_nonce_field( 'as_marketing_update_data_nonce', 'as_marketing_nonce' ); ?>

                <p>
                    <label for="excel_file">Seleziona il file Excel (formato .xlsx, .xls, .csv):</label><br>
                    <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </p>
                <p>
                    <input type="submit" name="as_marketing_upload_and_update" class="button button-primary" value="Carica e Aggiorna Grafici & Analisi">
                </p>
            </form>

            <p>
                Oppure, se il file `<?php echo AS_MARKETING_EXCEL_FILE_NAME; ?>` è già nella cartella `/csv/` e vuoi solo rigenerare i grafici e l'analisi strategica:<br>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <input type="hidden" name="action" value="as_marketing_update">
                    <input type="hidden" name="as_marketing_update_data" value="1">
                    <?php wp_nonce_field( 'as_marketing_update_data_nonce', 'as_marketing_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="Rigenera Grafici & Analisi">
                </form>
            </p>

            <p class="warning-text">
                <small><strong>Attenzione:</strong> L'aggiornamento dei grafici e dell'analisi strategica esegue uno script Python sul server. Questo processo potrebbe impiegare del tempo a seconda della dimensione dei dati e della complessità dell'analisi LLM. Se il tuo hosting limita l'esecuzione di comandi shell, o non ha `python3` nel PATH, questa funzione potrebbe non funzionare correttamente. È consigliato monitorare i log del server per verificare l'esito dell'esecuzione.</small>
            </p>
        </div>

        <div class="dashboard-section">
            <h2>Analisi Strategica Generale</h2>
            <div class="strategic-analysis-content">
                <?php echo nl2br( esc_html( $strategic_analysis_content ) ); ?>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-section">
                <h2>Panoramica Franchising</h2>

                <div class="chart-block">
                    <h3>1. Distribuzione Stato Lead - Franchising</h3>
                    <p class="chart-analysis">Questo grafico a torta mostra la percentuale di lead per il franchising che hanno raggiunto lo stato di 'Chiuso' (ovvero, un preventivo è stato emesso) rispetto a quelli che sono ancora nello stato 'Non Chiuso' (informazioni iniziali, non risponde, ecc.). Un tasso di conversione elevato indica un processo di vendita efficace dal contatto iniziale fino alla proposta commerciale. Monitorare questa metrica è fondamentale per valutare l'efficienza del tuo funnel di acquisizione lead.</p>
                    <div class="chart-container" id="chart-franchising-lead-status"></div>
                </div>

                <div class="chart-block">
                    <h3>2. Lead per Sorgente - Franchising</h3>
                    <p class="chart-analysis">Questo grafico a barre evidenzia le sorgenti principali da cui provengono i tuoi lead di franchising (es. 'Sito', 'LF', 'Diretto'). Identificare i canali più performanti ti permette di ottimizzare l'allocazione del budget marketing, investendo maggiormente nelle fonti che generano lead di qualità superiore e con maggiore probabilità di conversione. È utile anche per capire quali canali potrebbero necessitare di miglioramenti.</p>
                    <div class="chart-container" id="chart-franchising-leads-source"></div>
                </div>

                <div class="chart-block">
                    <h3>3. Volume Lead Mensile - Franchising</h3>
                    <p class="chart-analysis">Il grafico a linee 'Volume Lead Mensile' traccia l'andamento del numero di nuovi lead di franchising acquisiti ogni mese. Questa visualizzazione è cruciale per identificare tendenze stagionali, l'impatto di campagne marketing specifiche o eventi esterni. Un aumento costante indica una crescita sana nella generazione di opportunità, mentre cali improvvisi possono segnalare problemi nel funnel di acquisizione o inefficacia delle attività promozionali.</p>
                    <div class="chart-container" id="chart-franchising-monthly-leads"></div>
                </div>

                <div class="chart-block">
                    <h3>4. Top 10 Città per Volume Lead - Franchising</h3>
                    <p class="chart-analysis">Questo grafico mostra le dieci città che generano il maggior numero di lead per il franchising. Comprendere la distribuzione geografica dei tuoi lead ti permette di affinare le strategie di marketing localizzate, identificare mercati promettenti per l'espansione e personalizzare la comunicazione in base alle specificità territoriali. Concentrati sulle città con alto volume e potenziale di conversione.</p>
                    <div class="chart-container" id="chart-franchising-top-cities"></div>
                </div>
            </div>

            <div class="dashboard-section">
                <h2>Panoramica Prodotti</h2>

                <div class="chart-block">
                    <h3>1. Distribuzione Stato Lead - Prodotti</h3>
                    <p class="chart-analysis">Simile al franchising, questo grafico a torta per i prodotti indica la percentuale di lead che sono stati 'Chiusi' (ovvero, è stato effettuato un ordine o si è conclusa positivamente) rispetto a quelli 'Non Chiusi'. Questo ti dà una visione immediata della performance del tuo processo di vendita per i prodotti specifici, evidenziando quanto efficacemente i lead si trasformano in clienti paganti. Un basso tasso di chiusura potrebbe indicare problemi nella proposta di valore o nel follow-up.</p>
                    <div class="chart-container" id="chart-prodotti-lead-status"></div>
                </div>

                <div class="chart-block">
                    <h3>2. Lead per Sorgente - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a barre illustra le diverse sorgenti di acquisizione lead per i prodotti. Analizzare quali canali (es. 'Sito', 'Diretto') portano più lead è fondamentale per ottimizzare gli investimenti. Un canale che porta molti lead ma con basso tasso di chiusura potrebbe richiedere una revisione della qualità dei lead generati, mentre canali con meno volume ma alta conversione meritano più attenzione.</p>
                    <div class="chart-container" id="chart-prodotti-leads-source"></div>
                </div>

                <div class="chart-block">
                    <h3>3. Volume Lead Mensile - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a linee mostra il numero di lead di prodotto acquisiti ogni mese. L'analisi di questo trend ti aiuta a capire la risposta del mercato alle tue offerte di prodotti, l'efficacia delle promozioni e l'impatto di eventi esterni. È un indicatore chiave della domanda e della salute delle tue attività di lead generation per i prodotti.</p>
                    <div class="chart-container" id="chart-prodotti-monthly-leads"></div>
                </div>

                <div class="chart-block">
                    <h3>4. Top 10 Città per Volume Lead - Prodotti</h3>
                    <p class="chart-analysis">Questo grafico a barre identifica le dieci città da cui provengono la maggior parte dei lead di prodotto. Questa informazione è preziosa per strategie di marketing geo-mirate, campagne pubblicitarie locali o per l'identificazione di aree ad alto potenziale di crescita per la vendita dei prodotti. Potrebbe anche suggerire l'opportunità di eventi o partnership locali.</p>
                    <div class="chart-container" id="chart-prodotti-top-cities"></div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
