document.addEventListener('DOMContentLoaded', function() {
    // Check if the asMarketing object and its plugin_url property are available
    if (typeof asMarketing === 'undefined' || typeof asMarketing.plugin_url === 'undefined') {
        console.error('asMarketing localization object not found. Make sure it is passed correctly from PHP.');
        return;
    }

    const charts = [
        { id: 'chart-franchising-lead-status', url: 'data/franchising_lead_status_distribution.json' },
        { id: 'chart-franchising-leads-source', url: 'data/franchising_leads_by_source.json' },
        { id: 'chart-franchising-monthly-leads', url: 'data/franchising_monthly_leads.json' },
        { id: 'chart-franchising-top-cities', url: 'data/franchising_top_cities.json' },
        { id: 'chart-prodotti-lead-status', url: 'data/prodotti_lead_status_distribution.json' },
        { id: 'chart-prodotti-leads-source', url: 'data/prodotti_leads_by_source.json' },
        { id: 'chart-prodotti-monthly-leads', url: 'data/prodotti_monthly_leads.json' },
        { id: 'chart-prodotti-top-cities', url: 'data/prodotti_top_cities.json' }
    ];

    charts.forEach(chart => {
        const container = document.getElementById(chart.id);
        if (container) {
            // Use the localized plugin URL to construct the data URL
            const dataUrl = asMarketing.plugin_url + chart.url;

            fetch(dataUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status} for ${dataUrl}`);
                    }
                    return response.json();
                })
                .then(spec => {
                    // Embed the chart using Vega-Embed
                    vegaEmbed(container, spec, { actions: false }).catch(console.error);
                })
                .catch(error => {
                    console.error('Error loading or rendering chart:', chart.id, error);
                    container.innerHTML = `<p style="color: red; text-align: center;">Could not load chart: ${chart.id}. See console for details.</p>`;
                });
        }
    });
});
