import pandas as pd
import altair as alt
import os
import google.generativeai as genai # Assicurati di aver installato: pip install google-generativeai

# --- Configurazione LLM ---
# SOSTITUISCI 'YOUR_GEMINI_API_KEY' CON LA TUA VERA CHIAVE API GEMINI
# Non esporre questa chiave pubblicamente o nel codice sorgente visibile sul frontend.
GEMINI_API_KEY = 'AIzaSyDxFXOQm3RjCKjN8czGj9bPDEISNFXHa38'
genai.configure(api_key=GEMINI_API_KEY)
# --- Fine Configurazione LLM ---


# Definisci i percorsi assoluti per le cartelle del plugin (usando le costanti di PHP per coerenza)
CSV_FOLDER = '/var/www/vhosts/esvending.it/httpdocs/wp-content/plugins/analisi-strategica/csv/'
DATA_FOLDER = '/var/www/vhosts/esvending.it/httpdocs/wp-content/plugins/analisi-strategica/data/'

# Nome del file Excel unico
EXCEL_FILE_NAME = 'Commerciale.xlsx'
EXCEL_FULL_PATH = os.path.join(CSV_FOLDER, EXCEL_FILE_NAME)

# Nome del file per l'analisi strategica generata dall'LLM
STRATEGIC_ANALYSIS_FILE = os.path.join(DATA_FOLDER, 'strategic_analysis.txt')

# Assicurati che le cartelle di output esistano
os.makedirs(DATA_FOLDER, exist_ok=True)
os.makedirs(CSV_FOLDER, exist_ok=True) # Assicurati che anche la cartella CSV esista


# Funzione per eseguire lo script Python e catturare l'output per i log
def run_command(command):
    import subprocess
    process = subprocess.Popen(command, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = process.communicate()
    return stdout.decode(), stderr.decode(), process.returncode


# Funzione per generare l'analisi strategica con LLM
def generate_strategic_analysis(franchising_data_summary, prodotti_data_summary):
    model = genai.GenerativeModel('models/gemini-1.5-flash-8b')

    prompt = f"""
    Sei un esperto di marketing e vendite. Analizza i seguenti dati riassuntivi provenienti da un'azienda che gestisce lead per franchising e prodotti. Fornisci un'analisi concisa e pratica con consigli strategici di marketing basati su questi dati. La tua risposta deve essere strutturata e facile da leggere, con consigli chiari e azionabili.

    --- Dati Riassuntivi Franchising ---
    {franchising_data_summary}

    --- Dati Riassuntivi Prodotti ---
    {prodotti_data_summary}

    --- Consigli Strategici Richiesti ---
    Fornisci 3-5 punti chiave di analisi per il franchising e 3-5 per i prodotti. Per ogni punto, includi un suggerimento strategico concreto. Inizia ogni sezione (Franchising, Prodotti) con un breve paragrafo di introduzione e poi elenca i consigli. Concentrati su:
    - Tassi di chiusura
    - Efficacia delle sorgenti (canali)
    - Tendenze del volume di lead
    - Opportunità geografiche

    La risposta deve essere in italiano.
    """
    try:
        response = model.generate_content(prompt)
        return response.text
    except Exception as e:
        print(f"Errore durante la generazione dell'analisi strategica con LLM: {e}")
        return "Impossibile generare l'analisi strategica in questo momento a causa di un errore dell'LLM."

# Funzione principale per elaborare i dati e generare i grafici
def process_data_and_generate_charts():
    print("Inizio elaborazione dati e generazione grafici...")
    df_franchising = pd.DataFrame()
    df_prodotti = pd.DataFrame()

    try:
        if not os.path.exists(EXCEL_FULL_PATH):
            print(f"Errore: Il file Excel '{EXCEL_FULL_PATH}' non è stato trovato. Impossibile procedere.")
            with open(STRATEGIC_ANALYSIS_FILE, 'w') as f:
                f.write("Impossibile caricare il file Excel per l'analisi.")
            return

        # Carica i dati dai fogli specifici del file Excel
        try:
            df_franchising = pd.read_excel(EXCEL_FULL_PATH, sheet_name='Franchising', skiprows=8)
            print(f"Foglio 'Franchising' caricato con successo da {EXCEL_FULL_PATH}")
        except ValueError:
            print(f"Errore: Il foglio 'Franchising' non trovato. DataFrame Franchising vuoto.")
            df_franchising = pd.DataFrame()

        try:
            df_prodotti = pd.read_excel(EXCEL_FULL_PATH, sheet_name='Prodotti', skiprows=8)
            print(f"Foglio 'Prodotti' caricato con successo da {EXCEL_FULL_PATH}")
        except ValueError:
            print(f"Errore: Il foglio 'Prodotti' non trovato. DataFrame Prodotti vuoto.")
            df_prodotti = pd.DataFrame()

        # --- Data Cleaning e Standardizzazione per df_franchising ---
        franchising_data_summary = "Nessun dato di franchising disponibile."
        if not df_franchising.empty:
            df_franchising['DATA'] = pd.to_datetime(df_franchising['DATA'], errors='coerce')
            df_franchising.dropna(subset=['DATA'], inplace=True)
            df_franchising['DA'] = df_franchising['DA'].astype(str).str.lower().str.strip().replace('nan', 'Sconosciuto') # Sostituisci nan
            df_franchising['città'] = df_franchising['città'].fillna('')
            df_franchising['città'] = df_franchising['città'].apply(lambda x: ' '.join(word.capitalize() for word in x.split() if len(word) > 2 and not word.isupper()))
            df_franchising['Lead Status'] = 'Non Chiuso'
            df_franchising.loc[df_franchising['Azione 1'].astype(str).str.contains('preventivo|24\d{3}|25\d{3}|chiuso', case=False, na=False), 'Lead Status'] = 'Chiuso'
            df_franchising['Month-Year'] = df_franchising['DATA'].dt.to_period('M')

            # Riassunto per LLM
            total_leads_f = len(df_franchising)
            closed_leads_f = df_franchising[df_franchising['Lead Status'] == 'Chiuso'].shape[0]
            closure_rate_f = (closed_leads_f / total_leads_f) * 100 if total_leads_f > 0 else 0

            top_sources_f = df_franchising['DA'].value_counts().head(3).to_dict()
            monthly_leads_f = df_franchising.groupby('Month-Year').size().sort_index().to_dict()
            top_cities_f = df_franchising['città'].replace('', pd.NA).dropna().value_counts().head(3).to_dict() # Escludi città vuote

            franchising_data_summary = f"""
            Totale Lead: {total_leads_f}
            Lead Chiusi: {closed_leads_f}
            Tasso di Chiusura: {closure_rate_f:.2f}%
            Top 3 Sorgenti: {top_sources_f}
            Volume Lead Mensile (ultimi mesi): {str(list(monthly_leads_f.items())[-3:])}
            Top 3 Città: {top_cities_f}
            """
        else:
            print("DataFrame Franchising è vuoto. Impossibile generare riassunto.")


        # --- Data Cleaning e Standardizzazione per df_prodotti ---
        prodotti_data_summary = "Nessun dato di prodotti disponibile."
        if not df_prodotti.empty:
            df_prodotti['DATA'] = pd.to_datetime(df_prodotti['DATA'], errors='coerce')
            df_prodotti.dropna(subset=['DATA'], inplace=True)
            df_prodotti['DA'] = df_prodotti['DA'].astype(str).str.lower().str.strip().replace('nan', 'Sconosciuto') # Sostituisci nan
            df_prodotti['città'] = df_prodotti['città'].fillna('')
            df_prodotti['città'] = df_prodotti['città'].apply(lambda x: ' '.join(word.capitalize() for word in x.split() if len(word) > 2 and not word.isupper()))
            df_prodotti['Lead Status'] = 'Non Chiuso'
            df_prodotti.loc[df_prodotti['azione'].astype(str).str.contains('chiuso|1ordine', case=False, na=False), 'Lead Status'] = 'Chiuso'
            df_prodotti['Month-Year'] = df_prodotti['DATA'].dt.to_period('M')

            # Riassunto per LLM
            total_leads_p = len(df_prodotti)
            closed_leads_p = df_prodotti[df_prodotti['Lead Status'] == 'Chiuso'].shape[0]
            closure_rate_p = (closed_leads_p / total_leads_p) * 100 if total_leads_p > 0 else 0

            top_sources_p = df_prodotti['DA'].value_counts().head(3).to_dict()
            monthly_leads_p = df_prodotti.groupby('Month-Year').size().sort_index().to_dict()
            top_cities_p = df_prodotti['città'].replace('', pd.NA).dropna().value_counts().head(3).to_dict() # Escludi città vuote

            prodotti_data_summary = f"""
            Totale Lead: {total_leads_p}
            Lead Chiusi: {closed_leads_p}
            Tasso di Chiusura: {closure_rate_p:.2f}%
            Top 3 Sorgenti: {top_sources_p}
            Volume Lead Mensile (ultimi mesi): {str(list(monthly_leads_p.items())[-3:])}
            Top 3 Città: {top_cities_p}
            """
        else:
            print("DataFrame Prodotti è vuoto. Impossibile generare riassunto.")


        # --- Generazione Grafici ---
        print("Generazione file JSON per i grafici...")
        # (Codice per la generazione dei grafici rimane invariato come nelle versioni precedenti)
        # 1. Distribuzione Stato Lead (Pie Chart) Franchising
        if not df_franchising.empty:
            franchising_status_counts = df_franchising['Lead Status'].value_counts().reset_index()
            franchising_status_counts.columns = ['Lead Status', 'Count']
            franchising_status_counts['Percentage'] = (franchising_status_counts['Count'] / franchising_status_counts['Count'].sum())
            chart_franchising_status = alt.Chart(franchising_status_counts).mark_arc().encode(
                theta=alt.Theta(field="Count", type="quantitative"),
                color=alt.Color(field="Lead Status", type="nominal", title="Stato Lead"),
                tooltip=['Lead Status', 'Count', alt.Tooltip('Percentage', format='.1%', title='Percentuale')]
            ).properties(title='Distribuzione Stato Lead - Franchising')
            chart_franchising_status.save(os.path.join(DATA_FOLDER, 'franchising_lead_status_distribution.json'))

        # 2. Distribuzione Stato Lead (Pie Chart) Prodotti
        if not df_prodotti.empty:
            prodotti_status_counts = df_prodotti['Lead Status'].value_counts().reset_index()
            prodotti_status_counts.columns = ['Lead Status', 'Count']
            prodotti_status_counts['Percentage'] = (prodotti_status_counts['Count'] / prodotti_status_counts['Count'].sum())
            chart_prodotti_status = alt.Chart(prodotti_status_counts).mark_arc().encode(
                theta=alt.Theta(field="Count", type="quantitative"),
                color=alt.Color(field="Lead Status", type="nominal", title="Stato Lead"),
                tooltip=['Lead Status', 'Count', alt.Tooltip('Percentage', format='.1%', title='Percentuale')]
            ).properties(title='Distribuzione Stato Lead - Prodotti')
            chart_prodotti_status.save(os.path.join(DATA_FOLDER, 'prodotti_lead_status_distribution.json'))

        # 3. Leads by Source (DA) Franchising
        if not df_franchising.empty:
            franchising_da_counts = df_franchising['DA'].value_counts().reset_index()
            franchising_da_counts.columns = ['Source', 'Count']
            chart_franchising_da = alt.Chart(franchising_da_counts).mark_bar().encode(
                x=alt.X('Count', title='Numero Lead'),
                y=alt.Y('Source', sort='-x', title='Sorgente'),
                tooltip=['Source', 'Count']
            ).properties(title='Lead per Sorgente - Franchising')
            chart_franchising_da.save(os.path.join(DATA_FOLDER, 'franchising_leads_by_source.json'))

        # 4. Leads by Source (DA) Prodotti
        if not df_prodotti.empty:
            prodotti_da_counts = df_prodotti['DA'].value_counts().reset_index()
            prodotti_da_counts.columns = ['Source', 'Count']
            chart_prodotti_da = alt.Chart(prodotti_da_counts).mark_bar().encode(
                x=alt.X('Count', title='Numero Lead'),
                y=alt.Y('Source', sort='-x', title='Sorgente'),
                tooltip=['Source', 'Count']
            ).properties(title='Lead per Sorgente - Prodotti')
            chart_prodotti_da.save(os.path.join(DATA_FOLDER, 'prodotti_leads_by_source.json'))

        # 5. Monthly Lead Volume (Line Chart) Franchising
        if not df_franchising.empty:
            franchising_monthly_leads = df_franchising.groupby('Month-Year').size().reset_index(name='Count')
            franchising_monthly_leads['Month-Year'] = franchising_monthly_leads['Month-Year'].dt.to_timestamp()
            chart_franchising_monthly = alt.Chart(franchising_monthly_leads).mark_line(point=True).encode(
                x=alt.X('Month-Year', type='temporal', title='Mese'),
                y=alt.Y('Count', title='Numero Lead'),
                tooltip=[alt.Tooltip('Month-Year', format='%Y-%m', title='Mese'), 'Count']
            ).properties(title='Volume Lead Mensile - Franchising')
            chart_franchising_monthly.save(os.path.join(DATA_FOLDER, 'franchising_monthly_leads.json'))

        # 6. Monthly Lead Volume (Line Chart) Prodotti
        if not df_prodotti.empty:
            prodotti_monthly_leads = df_prodotti.groupby('Month-Year').size().reset_index(name='Count')
            prodotti_monthly_leads['Month-Year'] = prodotti_monthly_leads['Month-Year'].dt.to_timestamp()
            chart_prodotti_monthly = alt.Chart(prodotti_monthly_leads).mark_line(point=True).encode(
                x=alt.X('Month-Year', type='temporal', title='Mese'),
                y=alt.Y('Count', title='Numero Lead'),
                tooltip=[alt.Tooltip('Month-Year', format='%Y-%m', title='Mese'), 'Count']
            ).properties(title='Volume Lead Mensile - Prodotti')
            chart_prodotti_monthly.save(os.path.join(DATA_FOLDER, 'prodotti_monthly_leads.json'))

        # 7. Top 10 Cities by Lead Volume Franchising
        if not df_franchising.empty:
            franchising_city_counts = df_franchising['città'].value_counts().reset_index()
            franchising_city_counts.columns = ['Città', 'Count']
            franchising_city_counts = franchising_city_counts[franchising_city_counts['Città'] != '']
            top_10_cities_franchising = franchising_city_counts.head(10)
            chart_franchising_cities = alt.Chart(top_10_cities_franchising).mark_bar().encode(
                x=alt.X('Count', title='Numero Lead'),
                y=alt.Y('Città', sort='-x', title='Città'),
                tooltip=['Città', 'Count']
            ).properties(title='Top 10 Città per Volume Lead - Franchising')
            chart_franchising_cities.save(os.path.join(DATA_FOLDER, 'franchising_top_cities.json'))

        # 8. Top 10 Cities by Lead Volume Prodotti
        if not df_prodotti.empty:
            prodotti_city_counts = df_prodotti['città'].value_counts().reset_index()
            prodotti_city_counts.columns = ['Città', 'Count']
            prodotti_city_counts = prodotti_city_counts[prodotti_city_counts['Città'] != '']
            top_10_cities_prodotti = prodotti_city_counts.head(10)
            chart_prodotti_cities = alt.Chart(top_10_cities_prodotti).mark_bar().encode(
                x=alt.X('Count', title='Numero Lead'),
                y=alt.Y('Città', sort='-x', title='Città'),
                tooltip=['Città', 'Count']
            ).properties(title='Top 10 Città per Volume Lead - Prodotti')
            chart_prodotti_cities.save(os.path.join(DATA_FOLDER, 'prodotti_top_cities.json'))

        print("Generazione dei grafici JSON completata con successo.")

        # --- Generazione Analisi Strategica con LLM ---
        print("Generazione analisi strategica con LLM...")
        strategic_analysis_text = generate_strategic_analysis(franchising_data_summary, prodotti_data_summary)

        with open(STRATEGIC_ANALYSIS_FILE, 'w', encoding='utf-8') as f:
            f.write(strategic_analysis_text)
        print("Analisi strategica generata e salvata con successo.")

    except Exception as e:
        print(f"Si è verificato un errore durante l'elaborazione dei dati o la generazione dei grafici/analisi: {e}")
        with open(STRATEGIC_ANALYSIS_FILE, 'w', encoding='utf-8') as f:
            f.write(f"Errore nella generazione dell'analisi strategica: {e}")

if __name__ == "__main__":
    process_data_and_generate_charts()