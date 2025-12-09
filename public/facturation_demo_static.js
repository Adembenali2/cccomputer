// ==================
// STATIC DEMO DATA - Front-end only billing page
// ==================
// REMOVED: All API calls to /API/facturation_*.php endpoints
// REPLACED: All database/repository dependencies with static JavaScript data
// ==================

// ==================
// STATIC DEMO DATA - Clients
// ==================
const staticClients = [
    { id: 1, raison_sociale: 'Entreprise ABC', nom: 'Dupont', prenom: 'Jean', reference: 'CLI-001', numero_client: '001' },
    { id: 2, raison_sociale: 'Société XYZ', nom: 'Martin', prenom: 'Marie', reference: 'CLI-002', numero_client: '002' },
    { id: 3, raison_sociale: 'Corporation DEF', nom: 'Bernard', prenom: 'Pierre', reference: 'CLI-003', numero_client: '003' },
    { id: 4, raison_sociale: 'Groupe GHI', nom: 'Dubois', prenom: 'Sophie', reference: 'CLI-004', numero_client: '004' },
    { id: 5, raison_sociale: 'Compagnie JKL', nom: 'Leroy', prenom: 'Thomas', reference: 'CLI-005', numero_client: '005' }
];

// ==================
// STATIC DEMO DATA - Consumption Chart Data
// ==================
// Data structure: { clientId: { year: { labels, nbData, colorData, totalData }, month: { year: { month: { labels, nbData, colorData, totalData } } } } }
const staticChartData = {
    // All clients (null key)
    null: {
        year: {
            2022: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                nbData: [1200, 1350, 1100, 1400, 1300, 1250, 1500, 1450, 1600, 1550, 1700, 1800],
                colorData: [200, 250, 180, 300, 280, 220, 350, 320, 400, 380, 450, 500],
                totalData: [1400, 1600, 1280, 1700, 1580, 1470, 1850, 1770, 2000, 1930, 2150, 2300]
            },
            2023: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                nbData: [1900, 2000, 1850, 2100, 2050, 1950, 2200, 2150, 2400, 2350, 2500, 2600],
                colorData: [550, 600, 500, 650, 620, 580, 700, 680, 750, 720, 800, 850],
                totalData: [2450, 2600, 2350, 2750, 2670, 2530, 2900, 2830, 3150, 3070, 3300, 3450]
            },
            2024: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                nbData: [2700, 2800, 2650, 2900, 2850, 2750, 3000, 2950, 3200, 3150, 3300, 3400],
                colorData: [900, 950, 850, 1000, 980, 920, 1100, 1050, 1200, 1150, 1300, 1350],
                totalData: [3600, 3750, 3500, 3900, 3830, 3670, 4100, 4000, 4400, 4300, 4600, 4750]
            },
            2025: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                nbData: [3500, 3600, 3400, 3700, 3650, 3550, 3800, 3750, 4000, 3950, 4100, 4200],
                colorData: [1400, 1450, 1350, 1500, 1480, 1420, 1600, 1550, 1700, 1650, 1750, 1800],
                totalData: [4900, 5050, 4750, 5200, 5130, 4970, 5400, 5300, 5700, 5600, 5850, 6000]
            }
        },
        month: {
            2025: {
                0: { // January - daily data
                    labels: Array.from({length: 31}, (_, i) => `${i+1}`),
                    nbData: Array.from({length: 31}, () => Math.floor(Math.random() * 200) + 100),
                    colorData: Array.from({length: 31}, () => Math.floor(Math.random() * 50) + 30),
                    totalData: Array.from({length: 31}, (_, i) => (Math.floor(Math.random() * 200) + 100) + (Math.floor(Math.random() * 50) + 30))
                },
                10: { // November - daily data
                    labels: Array.from({length: 30}, (_, i) => `${i+1}`),
                    nbData: Array.from({length: 30}, () => Math.floor(Math.random() * 180) + 90),
                    colorData: Array.from({length: 30}, () => Math.floor(Math.random() * 45) + 25),
                    totalData: Array.from({length: 30}, (_, i) => (Math.floor(Math.random() * 180) + 90) + (Math.floor(Math.random() * 45) + 25))
                },
                11: { // December - daily data
                    labels: Array.from({length: 31}, (_, i) => `${i+1}`),
                    nbData: Array.from({length: 31}, () => Math.floor(Math.random() * 220) + 110),
                    colorData: Array.from({length: 31}, () => Math.floor(Math.random() * 55) + 35),
                    totalData: Array.from({length: 31}, (_, i) => (Math.floor(Math.random() * 220) + 110) + (Math.floor(Math.random() * 55) + 35))
                }
            }
        }
    },
    // Client 1
    1: {
        year: {
            2025: {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                nbData: [1200, 1300, 1150, 1400, 1350, 1250, 1500, 1450, 1600, 1550, 1700, 1800],
                colorData: [300, 350, 280, 400, 380, 320, 450, 420, 500, 480, 550, 600],
                totalData: [1500, 1650, 1430, 1800, 1730, 1570, 1950, 1870, 2100, 2030, 2250, 2400]
            }
        },
        month: {
            2025: {
                10: {
                    labels: Array.from({length: 30}, (_, i) => `${i+1}`),
                    nbData: Array.from({length: 30}, () => Math.floor(Math.random() * 60) + 40),
                    colorData: Array.from({length: 30}, () => Math.floor(Math.random() * 20) + 10),
                    totalData: Array.from({length: 30}, (_, i) => (Math.floor(Math.random() * 60) + 40) + (Math.floor(Math.random() * 20) + 10))
                }
            }
        }
    }
};

// ==================
// STATIC DEMO DATA - Consumption Table Data
// ==================
const staticTableData = {
    null: [ // All clients
        {
            id: 1,
            nom: 'HP LaserJet Pro M404dn',
            modele: 'M404dn',
            macAddress: 'AB:CD:EF:12:34:56',
            consommations: [
                { mois: '2025-09', periode: 'Septembre 2025 (20/09 → 20/10)', pagesNB: 8750, pagesCouleur: 0, totalPages: 8750 },
                { mois: '2025-10', periode: 'Octobre 2025 (20/10 → 20/11)', pagesNB: 9200, pagesCouleur: 150, totalPages: 9350 },
                { mois: '2025-11', periode: 'Novembre 2025 (20/11 → 20/12)', pagesNB: 8900, pagesCouleur: 200, totalPages: 9100 }
            ]
        },
        {
            id: 2,
            nom: 'Canon imageRUNNER ADVANCE C5535i',
            modele: 'C5535i',
            macAddress: '12:34:56:AB:CD:EF',
            consommations: [
                { mois: '2025-09', periode: 'Septembre 2025 (20/09 → 20/10)', pagesNB: 12000, pagesCouleur: 2500, totalPages: 14500 },
                { mois: '2025-10', periode: 'Octobre 2025 (20/10 → 20/11)', pagesNB: 13500, pagesCouleur: 2800, totalPages: 16300 },
                { mois: '2025-11', periode: 'Novembre 2025 (20/11 → 20/12)', pagesNB: 12800, pagesCouleur: 3000, totalPages: 15800 }
            ]
        }
    ],
    1: [ // Client 1
        {
            id: 1,
            nom: 'HP LaserJet Pro M404dn',
            modele: 'M404dn',
            macAddress: 'AB:CD:EF:12:34:56',
            consommations: [
                { mois: '2025-09', periode: 'Septembre 2025 (20/09 → 20/10)', pagesNB: 8750, pagesCouleur: 0, totalPages: 8750 },
                { mois: '2025-10', periode: 'Octobre 2025 (20/10 → 20/11)', pagesNB: 9200, pagesCouleur: 150, totalPages: 9350 },
                { mois: '2025-11', periode: 'Novembre 2025 (20/11 → 20/12)', pagesNB: 8900, pagesCouleur: 200, totalPages: 9100 }
            ]
        }
    ]
};

// ==================
// STATIC DEMO DATA - Summary/KPI Data
// ==================
const staticSummaryData = {
    1: {
        total_a_facturer: 8450.50,
        montant_paye: 3200.00,
        montant_non_paye: 5250.50,
        consommation_pages: { nb: 26850, color: 350, total: 27200 },
        facture_en_cours: {
            id: 101,
            numero: '2025-11',
            statut: 'brouillon',
            montant_ttc: 1250.75,
            periode: { debut: '2025-10-20', fin: '2025-11-20' }
        }
    },
    2: {
        total_a_facturer: 12500.00,
        montant_paye: 8500.00,
        montant_non_paye: 4000.00,
        consommation_pages: { nb: 38300, color: 8300, total: 46600 },
        facture_en_cours: null
    }
};

// ==================
// STATIC DEMO DATA - Invoices List
// ==================
const staticInvoicesData = {
    1: [
        {
            id: 101,
            numero: '2025-11',
            date: '2025-11-15',
            periode: { debut: '2025-10-20', fin: '2025-11-20' },
            type: 'Consommation',
            montantTTC: 1250.75,
            statut: 'brouillon'
        },
        {
            id: 100,
            numero: '2025-10',
            date: '2025-10-15',
            periode: { debut: '2025-09-20', fin: '2025-10-20' },
            type: 'Consommation',
            montantTTC: 1180.50,
            statut: 'envoyee'
        },
        {
            id: 99,
            numero: '2025-09',
            date: '2025-09-15',
            periode: { debut: '2025-08-20', fin: '2025-09-20' },
            type: 'Consommation',
            montantTTC: 1100.25,
            statut: 'payee'
        }
    ],
    2: [
        {
            id: 201,
            numero: '2025-11',
            date: '2025-11-15',
            periode: { debut: '2025-10-20', fin: '2025-11-20' },
            type: 'Consommation',
            montantTTC: 2450.00,
            statut: 'envoyee'
        }
    ]
};

// ==================
// STATIC DEMO DATA - Invoice Detail
// ==================
const staticInvoiceDetailData = {
    101: {
        id: 101,
        numero: '2025-11',
        date_creation: '2025-11-15',
        periode: { debut: '2025-10-20', fin: '2025-11-20' },
        type: 'Consommation',
        montant_ht: 1042.29,
        tva: 208.46,
        montant_ttc: 1250.75,
        statut: 'brouillon',
        pdf_genere: false,
        client: {
            id: 1,
            raison_sociale: 'Entreprise ABC',
            adresse: '123 Rue Example',
            code_postal: '75001',
            ville: 'Paris',
            email: 'contact@entreprise-abc.fr'
        },
        lignes: [
            { description: 'Pages N&B - Novembre 2025', type: 'Consommation', quantite: 8900, prixUnitaire: 0.05, total: 445.00 },
            { description: 'Pages Couleur - Novembre 2025', type: 'Consommation', quantite: 200, prixUnitaire: 0.15, total: 30.00 }
        ]
    }
};

// ==================
// STATIC DEMO DATA - Payments List
// ==================
const staticPaymentsData = {
    1: [
        {
            id: 1,
            facture_id: 99,
            facture_numero: '2025-09',
            montant: 1100.25,
            date_paiement: '2025-09-25',
            mode_paiement: 'Virement bancaire',
            reference: 'VIR-2025-09-25-001',
            commentaire: 'Paiement reçu',
            statut: 'recu',
            created_by_nom: 'Admin',
            created_by_prenom: 'System'
        },
        {
            id: 2,
            facture_id: 100,
            facture_numero: '2025-10',
            montant: 1180.50,
            date_paiement: '2025-10-28',
            mode_paiement: 'Chèque',
            reference: 'CHQ-2025-10-28-001',
            commentaire: 'En attente d\'encaissement',
            statut: 'en_cours',
            created_by_nom: 'Martin',
            created_by_prenom: 'Marie'
        }
    ],
    2: [
        {
            id: 3,
            facture_id: 201,
            facture_numero: '2025-11',
            montant: 2450.00,
            date_paiement: '2025-11-20',
            mode_paiement: 'Virement bancaire',
            reference: 'VIR-2025-11-20-001',
            commentaire: '',
            statut: 'recu',
            created_by_nom: 'Admin',
            created_by_prenom: 'System'
        }
    ]
};

// ==================
// GLOBAL VARIABLES
// ==================
let factureGeneree = false;
let consumptionChart = null;
let selectedClientId = null;

// ==================
// MOCK API FUNCTIONS - Simulate API calls with static data
// ==================

// REMOVED: Real API call to /API/facturation_search_clients.php
// REPLACED: Static client search function
function mockSearchClients(query, limit = 10) {
    const queryLower = query.toLowerCase();
    return staticClients.filter(client => {
        const searchText = `${client.raison_sociale} ${client.nom} ${client.prenom} ${client.reference}`.toLowerCase();
        return searchText.includes(queryLower);
    }).slice(0, limit);
}

// REMOVED: Real API call to /API/facturation_consumption_chart.php
// REPLACED: Static chart data function
function mockGetChartData(clientId, granularity, periodParams) {
    const dataKey = clientId || null;
    const year = periodParams.year || new Date().getFullYear();
    const month = periodParams.month;
    
    if (granularity === 'year') {
        const yearData = staticChartData[dataKey]?.year?.[year];
        if (yearData) {
            return yearData;
        }
        // Fallback to all clients data
        return staticChartData[null]?.year?.[year] || {
            labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
            nbData: Array(12).fill(0),
            colorData: Array(12).fill(0),
            totalData: Array(12).fill(0)
        };
    } else if (granularity === 'month' && month !== undefined) {
        const monthData = staticChartData[dataKey]?.month?.[year]?.[month];
        if (monthData) {
            return monthData;
        }
        // Generate default daily data for the month
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        return {
            labels: Array.from({length: daysInMonth}, (_, i) => `${i+1}`),
            nbData: Array.from({length: daysInMonth}, () => Math.floor(Math.random() * 100) + 50),
            colorData: Array.from({length: daysInMonth}, () => Math.floor(Math.random() * 30) + 10),
            totalData: Array.from({length: daysInMonth}, (_, i) => (Math.floor(Math.random() * 100) + 50) + (Math.floor(Math.random() * 30) + 10))
        };
    }
    
    // Default empty data
    return {
        labels: [],
        nbData: [],
        colorData: [],
        totalData: []
    };
}

// REMOVED: Real API call to /API/facturation_consumption_table.php
// REPLACED: Static table data function
function mockGetTableData(clientId, months = 3) {
    const dataKey = clientId || null;
    return staticTableData[dataKey] || staticTableData[null] || [];
}

// REMOVED: Real API call to /API/facturation_summary.php
// REPLACED: Static summary data function
function mockGetSummaryData(clientId) {
    return staticSummaryData[clientId] || {
        total_a_facturer: 0,
        montant_paye: 0,
        montant_non_paye: 0,
        consommation_pages: { nb: 0, color: 0, total: 0 },
        facture_en_cours: null
    };
}

// REMOVED: Real API call to /API/facturation_factures_list.php
// REPLACED: Static invoices list function
function mockGetInvoicesList(clientId) {
    return staticInvoicesData[clientId] || [];
}

// REMOVED: Real API call to /API/facturation_facture_detail.php
// REPLACED: Static invoice detail function
function mockGetInvoiceDetail(factureId) {
    return staticInvoiceDetailData[factureId] || null;
}

// REMOVED: Real API call to /API/facturation_payments_list.php
// REPLACED: Static payments list function
function mockGetPaymentsList(clientId) {
    return staticPaymentsData[clientId] || [];
}

// REMOVED: Real API call to /API/facturation_invoice.php
// REPLACED: Static invoice data function (for current invoice)
function mockGetInvoiceData(clientId, periodStart, periodEnd) {
    // Return mock consumption data for the period
    return {
        total: {
            nb: 8900,
            color: 200,
            total: 9100
        }
    };
}

// ==================
// CLIENT SEARCH FUNCTIONS
// ==================
function initClientSearch() {
    const searchInput = document.getElementById('clientSearchInput');
    const dropdown = document.getElementById('clientSearchDropdown');
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    const btnRemove = document.getElementById('btnRemoveClient');
    
    if (!searchInput || !dropdown || !selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    let searchTimeout = null;
    
    // REMOVED: Real API call, REPLACED: Static client search
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            dropdown.style.display = 'none';
            if (selectedClientId) {
                clearClientSelection();
            }
            return;
        }
        
        searchTimeout = setTimeout(() => {
            performClientSearch(query, dropdown);
        }, 200);
    });
    
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
    
    if (btnRemove) {
        btnRemove.addEventListener('click', () => {
            clearClientSelection();
        });
    }
    
    searchInput.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.dropdown-item');
        if (items.length === 0) return;
        
        const currentIndex = Array.from(items).findIndex(item => item.classList.contains('highlighted'));
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === nextIndex);
            });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === prevIndex);
            });
        } else if (e.key === 'Enter' && currentIndex >= 0) {
            e.preventDefault();
            items[currentIndex].click();
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
            searchInput.blur();
        }
    });
}

// REMOVED: Real API call to /API/facturation_search_clients.php
// REPLACED: Static client search using mockSearchClients
function performClientSearch(query, dropdown) {
    dropdown.innerHTML = '<div class="dropdown-item empty-state">Recherche...</div>';
    dropdown.style.display = 'block';
    
    // Simulate API delay
    setTimeout(() => {
        const clients = mockSearchClients(query, 10);
        
        dropdown.innerHTML = '';
        
        if (clients.length === 0) {
            dropdown.innerHTML = '<div class="dropdown-item empty-state">Aucun client trouvé</div>';
            return;
        }
        
        clients.forEach(client => {
            const item = document.createElement('div');
            item.className = 'dropdown-item';
            
            const name = highlightMatch(client.raison_sociale || client.name, query);
            const details = `${client.prenom || ''} ${client.nom || ''} • ${client.reference || client.numero_client || ''}`.trim();
            
            item.innerHTML = `
                <div class="dropdown-item-main">${name}</div>
                <div class="dropdown-item-sub">${details}</div>
            `;
            
            item.addEventListener('click', () => {
                selectClient(client.id, client.raison_sociale || client.name);
                document.getElementById('clientSearchInput').value = '';
                dropdown.style.display = 'none';
            });
            
            item.addEventListener('mouseenter', () => {
                dropdown.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('highlighted'));
                item.classList.add('highlighted');
            });
            
            dropdown.appendChild(item);
        });
    }, 150); // Simulate network delay
}

function highlightMatch(text, query) {
    if (!query) return text;
    const regex = new RegExp(`(${query})`, 'gi');
    return text.replace(regex, '<strong>$1</strong>');
}

function selectClient(clientId, clientName) {
    selectedClientId = clientId;
    
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    if (!selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    selectedName.textContent = clientName;
    selectedDisplay.style.display = 'flex';
    
    const tabsSection = document.getElementById('tabsSection');
    if (tabsSection) {
        tabsSection.style.display = 'block';
    }
    
    // Update all data with static demo data
    reloadBillingData();
    updateResumeKPIs();
    updateFactureEnCours();
    updatePaiementsDisplay();
    updateFacturesList();
}

function clearClientSelection() {
    selectedClientId = null;
    
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    if (selectedDisplay) {
        selectedDisplay.style.display = 'none';
    }
    
    const searchInput = document.getElementById('clientSearchInput');
    if (searchInput) {
        searchInput.value = '';
    }
    
    const tabsSection = document.getElementById('tabsSection');
    if (tabsSection) {
        tabsSection.style.display = 'none';
    }
    
    reloadBillingData();
}

// ==================
// CHART FUNCTIONS
// ==================
function getPeriodParams() {
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const params = {};
    
    if (granularityType === 'year') {
        const yearSelect = document.getElementById('chartYear');
        if (yearSelect) {
            params.year = parseInt(yearSelect.value);
        }
    } else if (granularityType === 'month') {
        const yearSelect = document.getElementById('chartMonthYear');
        const monthSelect = document.getElementById('chartMonth');
        if (yearSelect) params.year = parseInt(yearSelect.value);
        if (monthSelect) params.month = parseInt(monthSelect.value);
    }
    
    return params;
}

// REMOVED: Real API call to /API/facturation_consumption_chart.php
// REPLACED: Static chart data from mockGetChartData
async function initConsumptionChart() {
    const ctx = document.getElementById('consumptionChart');
    if (!ctx) return;
    
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const periodParams = getPeriodParams();
    
    const noDataMessage = document.getElementById('chartNoDataMessage');
    const chartContainer = document.querySelector('.chart-container');
    if (noDataMessage) {
        noDataMessage.style.display = 'block';
        noDataMessage.textContent = 'Chargement des données...';
    }
    if (chartContainer) {
        chartContainer.style.display = 'none';
    }
    
    try {
        // REMOVED: fetch('/API/facturation_consumption_chart.php?...')
        // REPLACED: Get static demo data
        const chartData = mockGetChartData(selectedClientId, granularityType, periodParams);
        
        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 300));
        
        const hasData = chartData.nbData && chartData.colorData && 
            (chartData.nbData.some(val => val > 0) || chartData.colorData.some(val => val > 0));
        
        if (noDataMessage) {
            noDataMessage.style.display = hasData ? 'none' : 'block';
            noDataMessage.textContent = 'Aucun relevé pour cette période.';
        }
        if (chartContainer) {
            chartContainer.style.display = 'block';
        }
        
        const datasets = [
            {
                label: 'Noir & Blanc',
                data: chartData.nbData,
                borderColor: 'rgb(30, 41, 59)',
                backgroundColor: 'rgba(30, 41, 59, 0.08)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.3,
                pointRadius: 4.5,
                pointHoverRadius: 7,
                pointBackgroundColor: 'rgb(30, 41, 59)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2.5,
                pointHoverBorderWidth: 3,
                pointHoverBackgroundColor: 'rgb(30, 41, 59)'
            },
            {
                label: 'Couleur',
                data: chartData.colorData,
                borderColor: 'rgb(139, 92, 246)',
                backgroundColor: 'rgba(139, 92, 246, 0.08)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.3,
                pointRadius: 4.5,
                pointHoverRadius: 7,
                pointBackgroundColor: 'rgb(139, 92, 246)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2.5,
                pointHoverBorderWidth: 3,
                pointHoverBackgroundColor: 'rgb(139, 92, 246)'
            },
            {
                label: 'Total',
                data: chartData.totalData,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.08)',
                borderWidth: 2.5,
                fill: true,
                tension: 0.3,
                pointRadius: 4.5,
                pointHoverRadius: 7,
                pointBackgroundColor: 'rgb(59, 130, 246)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2.5,
                pointHoverBorderWidth: 3,
                pointHoverBackgroundColor: 'rgb(59, 130, 246)',
                borderDash: [6, 4]
            }
        ];
        
        const config = {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1200,
                    easing: 'easeInOutQuart'
                },
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: {
                                family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                                size: 12,
                                weight: '500'
                            },
                            generateLabels: function(chart) {
                                const original = Chart.defaults.plugins.legend.labels.generateLabels;
                                const labels = original.call(this, chart);
                                labels.forEach((label, index) => {
                                    if (index === 0) {
                                        label.text = '◼ Noir & Blanc';
                                    } else if (index === 1) {
                                        label.text = '◼ Couleur';
                                    } else if (index === 2) {
                                        label.text = '◼ Total';
                                    }
                                });
                                return labels;
                            },
                            onClick: function(e, legendItem, legend) {
                                const index = legendItem.datasetIndex;
                                const chart = legend.chart;
                                const meta = chart.getDatasetMeta(index);
                                meta.hidden = meta.hidden === null ? !chart.data.datasets[index].hidden : null;
                                chart.update();
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed.y.toLocaleString('fr-FR') + ' pages';
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fr-FR');
                            },
                            font: {
                                family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                                size: 11
                            },
                            padding: 8
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.04)',
                            drawBorder: false,
                            lineWidth: 1
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                                size: 11
                            },
                            padding: 8
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        };
        
        if (consumptionChart) {
            consumptionChart.destroy();
        }
        
        consumptionChart = new Chart(ctx, config);
        
        if (chartContainer) {
            chartContainer.style.display = 'block';
        }
    } catch (error) {
        console.error('Erreur chargement graphique:', error);
        if (noDataMessage) {
            noDataMessage.style.display = 'block';
            noDataMessage.textContent = `Erreur lors du chargement des données: ${error.message}`;
        }
        if (chartContainer) {
            chartContainer.style.display = 'none';
        }
    }
}

async function reloadBillingData() {
    const chartNoDataMessage = document.getElementById('chartNoDataMessage');
    const chartContainer = document.querySelector('.chart-container');
    if (chartNoDataMessage) {
        chartNoDataMessage.style.display = 'block';
        chartNoDataMessage.textContent = 'Chargement des données...';
    }
    if (chartContainer) {
        chartContainer.style.display = 'none';
    }
    
    const tbody = document.getElementById('tableConsommationBody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem;">Chargement des données...</td></tr>';
    }
    
    try {
        await Promise.all([
            initConsumptionChart(),
            updateTableConsommation()
        ]);
    } catch (error) {
        console.error('Erreur lors du rechargement des données:', error);
    }
}

function updateConsumptionChart() {
    initConsumptionChart();
}

function updateGranularityControls() {
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const yearControls = document.getElementById('granularityYearControls');
    const monthControls = document.getElementById('granularityMonthControls');
    
    if (yearControls) yearControls.style.display = 'none';
    if (monthControls) monthControls.style.display = 'none';
    
    if (granularityType === 'year' && yearControls) {
        yearControls.style.display = 'flex';
    } else if (granularityType === 'month' && monthControls) {
        monthControls.style.display = 'flex';
    }
}

function initDefaultPeriodValues() {
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth();
    
    ['chartYear', 'chartMonthYear'].forEach(selectId => {
        const select = document.getElementById(selectId);
        if (select) {
            const option = select.querySelector(`option[value="${currentYear}"]`);
            if (option) option.selected = true;
        }
    });
    
    const monthSelect = document.getElementById('chartMonth');
    if (monthSelect) {
        const option = monthSelect.querySelector(`option[value="${currentMonth}"]`);
        if (option) option.selected = true;
    }
}

// ==================
// TABLE FUNCTIONS
// ==================
// REMOVED: Real API call to /API/facturation_consumption_table.php
// REPLACED: Static table data from mockGetTableData
async function updateTableConsommation() {
    const tbody = document.getElementById('tableConsommationBody');
    if (!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem;">Chargement des données...</td></tr>';
    
    try {
        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 300));
        
        // REMOVED: fetch('/API/facturation_consumption_table.php?...')
        // REPLACED: Get static demo data
        const imprimantes = mockGetTableData(selectedClientId, 3);
        
        if (imprimantes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">Aucune donnée de consommation disponible pour cette période.</td></tr>';
            return;
        }
        
        tbody.innerHTML = '';
        
        imprimantes.forEach(imprimante => {
            const consommationsFiltrees = imprimante.consommations || [];
            
            if (consommationsFiltrees.length === 0) return;
            
            consommationsFiltrees.forEach((consommation, index) => {
                const periode = consommation.periode || consommation.mois;
                
                const tr = document.createElement('tr');
                
                if (index === 0) {
                    const tdImprimante = document.createElement('td');
                    tdImprimante.setAttribute('rowspan', consommationsFiltrees.length);
                    tdImprimante.innerHTML = `
                        <div>${imprimante.nom || 'Inconnu'}</div>
                        <small>Modèle ${imprimante.modele || 'Inconnu'}</small>
                    `;
                    tr.appendChild(tdImprimante);
                }
                
                if (index === 0) {
                    const tdMac = document.createElement('td');
                    tdMac.setAttribute('rowspan', consommationsFiltrees.length);
                    tdMac.textContent = imprimante.macAddress || '';
                    tr.appendChild(tdMac);
                }
                
                const tdNb = document.createElement('td');
                tdNb.textContent = (consommation.pagesNB || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdNb);
                
                const tdColor = document.createElement('td');
                tdColor.textContent = (consommation.pagesCouleur || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdColor);
                
                const tdTotal = document.createElement('td');
                tdTotal.textContent = (consommation.totalPages || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdTotal);
                
                const tdMois = document.createElement('td');
                tdMois.textContent = periode;
                tr.appendChild(tdMois);
                
                tbody.appendChild(tr);
            });
        });
    } catch (error) {
        console.error('Erreur chargement tableau consommation:', error);
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #ef4444;">Erreur lors du chargement des données: ${error.message}</td></tr>`;
    }
}

// REMOVED: Real API call for export
// REPLACED: Export from static data
async function exportTableConsommation() {
    if (!selectedClientId) {
        alert('Veuillez sélectionner un client pour exporter les données.');
        return;
    }
    
    try {
        const imprimantes = mockGetTableData(selectedClientId, 3);
        
        if (imprimantes.length === 0) {
            alert('Aucune donnée de consommation disponible à exporter.');
            return;
        }
        
        const data = [];
        data.push(['Imprimante', 'MAC address', 'Pages N&B', 'Pages couleur', 'Total pages', 'Période']);
        
        imprimantes.forEach(imprimante => {
            imprimante.consommations.forEach((consommation, index) => {
                const row = [];
                
                if (index === 0) {
                    row.push(`${imprimante.nom} (Modèle ${imprimante.modele})`);
                } else {
                    row.push('');
                }
                
                if (index === 0) {
                    row.push(imprimante.macAddress);
                } else {
                    row.push('');
                }
                
                row.push(consommation.pagesNB);
                row.push(consommation.pagesCouleur);
                row.push(consommation.totalPages);
                row.push(consommation.periode);
                
                data.push(row);
            });
        });
        
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(data);
        
        const colWidths = [
            { wch: 30 },
            { wch: 18 },
            { wch: 12 },
            { wch: 14 },
            { wch: 12 },
            { wch: 30 }
        ];
        ws['!cols'] = colWidths;
        
        XLSX.utils.book_append_sheet(wb, ws, 'Consommation');
        
        const dateStr = new Date().toISOString().split('T')[0].replace(/-/g, '');
        const filename = `consommation-client-${selectedClientId}-${dateStr}.xlsx`;
        
        XLSX.writeFile(wb, filename);
    } catch (error) {
        console.error('Erreur export consommation:', error);
        alert(`Erreur lors de l'export des données de consommation: ${error.message}`);
    }
}

// ==================
// SUMMARY/KPI FUNCTIONS
// ==================
// REMOVED: Real API call to /API/facturation_summary.php
// REPLACED: Static summary data from mockGetSummaryData
function updateResumeKPIs() {
    if (!selectedClientId) {
        document.getElementById('kpiTotalFacturer').textContent = '—';
        document.getElementById('kpiMontantNonPaye').textContent = '—';
        document.getElementById('kpiMontantPaye').textContent = '—';
        document.getElementById('kpiConsoPages').textContent = '—';
        return;
    }
    
    try {
        const data = mockGetSummaryData(selectedClientId);
        
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
        };
        
        document.getElementById('kpiTotalFacturer').textContent = formatCurrency(data.total_a_facturer);
        document.getElementById('kpiMontantPaye').textContent = formatCurrency(data.montant_paye);
        document.getElementById('kpiMontantNonPaye').textContent = formatCurrency(data.montant_non_paye);
        document.getElementById('kpiConsoPages').textContent = `N&B : ${data.consommation_pages.nb.toLocaleString('fr-FR')} | Couleur : ${data.consommation_pages.color.toLocaleString('fr-FR')}`;
    } catch (error) {
        console.error('Erreur chargement résumé:', error);
    }
}

// ==================
// INVOICE FUNCTIONS
// ==================
// REMOVED: Real API call to /API/facturation_invoice.php
// REPLACED: Static invoice data from mockGetInvoiceData
function updateFactureEnCours() {
    if (!selectedClientId) {
        return;
    }
    
    const now = new Date();
    const currentDay = now.getDate();
    const currentMonth = now.getMonth();
    const currentYear = now.getFullYear();
    
    let periodStartMonth = currentMonth - 1;
    let periodStartYear = currentYear;
    if (periodStartMonth < 0) {
        periodStartMonth = 11;
        periodStartYear--;
    }
    
    const periodStart = new Date(periodStartYear, periodStartMonth, 20);
    const periodEnd = new Date(currentYear, currentMonth, 20);
    
    const formatDate = (date) => {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    };
    
    const periodEl = document.getElementById('facturePeriod');
    if (periodEl) {
        periodEl.textContent = `Période : ${formatDate(periodStart)} – ${formatDate(periodEnd)}`;
    }
    
    const factureNumEl = document.getElementById('factureNum');
    if (factureNumEl) {
        const monthStr = String(currentMonth + 1).padStart(2, '0');
        factureNumEl.textContent = `Facture ${currentYear}-${monthStr} (brouillon)`;
    }
    
    try {
        // REMOVED: fetch('/API/facturation_invoice.php?...')
        // REPLACED: Get static demo data
        const invoiceData = mockGetInvoiceData(selectedClientId, periodStart, periodEnd);
        
        const consoNBEl = document.getElementById('factureConsoNB');
        const consoCouleurEl = document.getElementById('factureConsoCouleur');
        if (consoNBEl && consoCouleurEl) {
            const total = invoiceData.total || {};
            const consoNB = total.nb || 0;
            const consoCouleur = total.color || 0;
            
            consoNBEl.textContent = `${consoNB.toLocaleString('fr-FR')} pages`;
            consoCouleurEl.textContent = `${consoCouleur.toLocaleString('fr-FR')} pages`;
        }
        
        const montantTTCEl = document.getElementById('factureMontantTTC');
        if (montantTTCEl) {
            const prixNB = 0.05;
            const prixCouleur = 0.15;
            const total = invoiceData.total || {};
            const montantHT = (total.nb || 0) * prixNB + (total.color || 0) * prixCouleur;
            const tva = montantHT * 0.20;
            const montantTTC = montantHT + tva;
            
            const formatted = montantTTC.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            montantTTCEl.textContent = formatted + ' €';
        }
    } catch (error) {
        console.error('Erreur chargement facture:', error);
        const consoNBEl = document.getElementById('factureConsoNB');
        const consoCouleurEl = document.getElementById('factureConsoCouleur');
        if (consoNBEl && consoCouleurEl) {
            consoNBEl.textContent = '0 pages';
            consoCouleurEl.textContent = '0 pages';
        }
    }
    
    const btnOuvrirFacture = document.getElementById('btnOuvrirFacture');
    const btnGenererFacture = document.getElementById('btnGenererFacture');
    const restrictionMessage = document.getElementById('factureRestrictionMessage');
    
    const isDay20 = currentDay === 20;
    
    if (factureGeneree) {
        if (btnOuvrirFacture) btnOuvrirFacture.style.display = 'inline-flex';
        if (btnGenererFacture) btnGenererFacture.style.display = 'none';
        if (restrictionMessage) restrictionMessage.style.display = 'none';
    } else {
        if (btnOuvrirFacture) btnOuvrirFacture.style.display = 'none';
        if (btnGenererFacture) {
            btnGenererFacture.style.display = 'inline-flex';
            if (isDay20) {
                btnGenererFacture.disabled = false;
                btnGenererFacture.style.opacity = '1';
                btnGenererFacture.style.cursor = 'pointer';
                if (restrictionMessage) restrictionMessage.style.display = 'none';
            } else {
                btnGenererFacture.disabled = true;
                btnGenererFacture.style.opacity = '0.5';
                btnGenererFacture.style.cursor = 'not-allowed';
                if (restrictionMessage) restrictionMessage.style.display = 'block';
            }
        }
    }
}

function genererFacture() {
    const now = new Date();
    const currentDay = now.getDate();
    
    if (currentDay !== 20) {
        alert('La génération de la facture n\'est possible que le 20 de chaque mois.');
        return;
    }
    
    factureGeneree = true;
    
    const factureNumEl = document.getElementById('factureNum');
    if (factureNumEl) {
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth();
        const monthStr = String(currentMonth + 1).padStart(2, '0');
        factureNumEl.textContent = `Facture #${currentYear}-${monthStr}`;
    }
    
    updateFactureEnCours();
    alert('Facture générée avec succès !');
}

// REMOVED: Real API call to /API/facturation_factures_list.php
// REPLACED: Static invoices list from mockGetInvoicesList
async function updateFacturesList() {
    if (!selectedClientId) {
        const tbody = document.getElementById('facturesListBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">Sélectionnez un client pour voir les factures</td></tr>';
        }
        return;
    }
    
    const tbody = document.getElementById('facturesListBody');
    if (!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem;">Chargement des factures...</td></tr>';
    
    try {
        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 300));
        
        // REMOVED: fetch('/API/facturation_factures_list.php?...')
        // REPLACED: Get static demo data
        const factures = mockGetInvoicesList(selectedClientId);
        
        if (factures.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">Aucune facture trouvée</td></tr>';
            return;
        }
        
        const formatDate = (dateStr) => {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('fr-FR');
        };
        
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
        };
        
        const getStatutBadge = (statut) => {
            const badges = {
                'brouillon': '<span class="badge badge-draft">Brouillon</span>',
                'envoyee': '<span class="badge badge-sent">Envoyée</span>',
                'payee': '<span class="badge badge-paid">Payée</span>',
                'en_retard': '<span class="badge badge-overdue">En retard</span>'
            };
            return badges[statut] || '';
        };
        
        tbody.innerHTML = factures.map(facture => {
            let periode = '—';
            if (facture.periode && facture.periode.debut && facture.periode.fin) {
                periode = `${formatDate(facture.periode.debut)} - ${formatDate(facture.periode.fin)}`;
            }
            
            return `<tr class="facture-row" data-facture-id="${facture.id}" style="cursor: pointer;">
                    <td>${facture.numero || '—'}</td>
                    <td>${formatDate(facture.date)}</td>
                    <td>${periode}</td>
                    <td>${facture.type || '—'}</td>
                    <td>${formatCurrency(facture.montantTTC || 0)}</td>
                    <td>${getStatutBadge(facture.statut)}</td>
                </tr>`;
        }).join('');
        
        document.querySelectorAll('.facture-row').forEach(row => {
            row.addEventListener('click', () => {
                const factureId = parseInt(row.dataset.factureId);
                displayFactureDetail(factureId);
            });
        });
    } catch (error) {
        console.error('Erreur chargement factures:', error);
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #ef4444;">Erreur lors du chargement des factures: ${error.message}</td></tr>`;
    }
}

// REMOVED: Real API call to /API/facturation_facture_detail.php
// REPLACED: Static invoice detail from mockGetInvoiceDetail
async function displayFactureDetail(factureId) {
    const detailPanel = document.getElementById('factureDetail');
    if (!detailPanel) return;
    
    detailPanel.innerHTML = '<div class="content-card"><div class="card-body"><p>Chargement...</p></div></div>';
    
    try {
        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 300));
        
        // REMOVED: fetch('/API/facturation_facture_detail.php?...')
        // REPLACED: Get static demo data
        const facture = mockGetInvoiceDetail(factureId);
        
        if (!facture) {
            throw new Error('Facture non trouvée');
        }
        
        const formatDate = (dateStr) => {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('fr-FR');
        };
        
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
        };
        
        const statutBadge = {
            'brouillon': '<span class="badge badge-draft">Brouillon</span>',
            'envoyee': '<span class="badge badge-sent">Envoyée</span>',
            'payee': '<span class="badge badge-paid">Payée</span>',
            'en_retard': '<span class="badge badge-overdue">En retard</span>'
        };
        
        let periodeHtml = '—';
        if (facture.periode && facture.periode.debut && facture.periode.fin) {
            periodeHtml = `${formatDate(facture.periode.debut)} - ${formatDate(facture.periode.fin)}`;
        }
        
        let lignesHtml = facture.lignes.map(ligne => `
            <tr>
                <td>${ligne.description}</td>
                <td>${ligne.type}</td>
                <td>${ligne.quantite}</td>
                <td>${formatCurrency(ligne.prixUnitaire)}</td>
                <td><strong>${formatCurrency(ligne.total)}</strong></td>
            </tr>
        `).join('');
    
        detailPanel.innerHTML = `
        <div class="content-card">
            <div class="card-header">
                <h3>Détail de la facture ${facture.numero}</h3>
            </div>
            <div class="card-body">
                <div class="facture-detail-info">
                    <div class="detail-section">
                        <h4>Informations client</h4>
                        <div class="detail-field">
                            <span class="detail-label">Nom :</span>
                            <span class="detail-value">${facture.client.raison_sociale}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Adresse :</span>
                            <span class="detail-value">${facture.client.adresse}, ${facture.client.code_postal} ${facture.client.ville}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Email :</span>
                            <span class="detail-value">${facture.client.email}</span>
                        </div>
                    </div>
                    <div class="detail-section">
                        <h4>Informations facture</h4>
                        <div class="detail-field">
                            <span class="detail-label">Date :</span>
                            <span class="detail-value">${formatDate(facture.date_creation)}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Période :</span>
                            <span class="detail-value">${periodeHtml}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Type :</span>
                            <span class="detail-value">${facture.type}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Statut :</span>
                            <span class="detail-value">${statutBadge[facture.statut] || ''}</span>
                        </div>
                    </div>
                </div>
                <div class="detail-actions">
                    ${facture.pdf_genere
                        ? `<button type="button" class="btn-secondary" onclick="alert('Voir facture PDF')">Voir facture</button>`
                        : `<button type="button" class="btn-secondary" onclick="alert('Générer PDF')">Générer PDF</button>`
                    }
                    <button type="button" class="btn-primary" onclick="alert('Envoyer au client')">Envoyer au client</button>
                </div>
                <div class="detail-table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Quantité</th>
                                <th>Prix unitaire</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${lignesHtml}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align:right;"><strong>Sous-total HT :</strong></td>
                                <td><strong>${formatCurrency(facture.montant_ht)}</strong></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="text-align:right;"><strong>TVA (20%) :</strong></td>
                                <td><strong>${formatCurrency(facture.tva)}</strong></td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="4" style="text-align:right;"><strong>Total TTC :</strong></td>
                                <td><strong>${formatCurrency(facture.montant_ttc)}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="detail-link">
                    <a href="#" onclick="event.preventDefault(); document.querySelector('[data-tab=paiements]').click();">Voir paiements liés</a>
                </div>
            </div>
        </div>
    `;
    } catch (error) {
        console.error('Erreur chargement détail facture:', error);
        detailPanel.innerHTML = `
            <div class="content-card">
                <div class="card-body">
                    <p style="color: #ef4444;">Erreur lors du chargement de la facture: ${error.message}</p>
                </div>
            </div>
        `;
    }
}

async function ouvrirHistoriqueFactures() {
    const modal = document.getElementById('modalHistoriqueFactures');
    if (!modal) return;
    
    await updateFacturesList();
    modal.style.display = 'block';
}

// ==================
// PAYMENTS FUNCTIONS
// ==================
// REMOVED: Real API call to /API/facturation_payment_create.php
// REPLACED: Mock payment creation (no real backend)
document.getElementById('formAddPayment')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    if (!selectedClientId) {
        alert('Veuillez sélectionner un client d\'abord.');
        return;
    }
    
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const date = document.getElementById('paymentDate').value;
    const mode = document.getElementById('paymentMode').value;
    const ref = document.getElementById('paymentRef').value;
    const comment = document.getElementById('paymentComment').value;
    
    if (isNaN(amount) || amount <= 0) {
        alert('Montant invalide');
        return;
    }
    if (!date) {
        alert('Date de paiement requise');
        return;
    }
    if (!mode) {
        alert('Mode de paiement requis');
        return;
    }
    
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enregistrement...';
    
    try {
        // REMOVED: fetch('/API/facturation_payment_create.php', ...)
        // REPLACED: Mock payment creation - just add to static data
        await new Promise(resolve => setTimeout(resolve, 500)); // Simulate API delay
        
        // Add payment to static data (for demo purposes)
        if (!staticPaymentsData[selectedClientId]) {
            staticPaymentsData[selectedClientId] = [];
        }
        staticPaymentsData[selectedClientId].unshift({
            id: Date.now(),
            facture_id: null,
            facture_numero: null,
            montant: amount,
            date_paiement: date,
            mode_paiement: mode,
            reference: ref || '',
            commentaire: comment || '',
            statut: 'en_cours',
            created_by_nom: 'Demo',
            created_by_prenom: 'User'
        });
        
        document.getElementById('formAddPayment').reset();
        document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
        
        await updatePaiementsDisplay();
        await updateResumeKPIs();
        
        alert('Paiement enregistré avec succès ! (Mode démo)');
    } catch (error) {
        console.error('Erreur création paiement:', error);
        alert('Erreur: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

// REMOVED: Real API call to /API/facturation_payments_list.php
// REPLACED: Static payments list from mockGetPaymentsList
async function updatePaiementsDisplay() {
    if (!selectedClientId) {
        return;
    }
    
    try {
        // Simulate API delay
        await new Promise(resolve => setTimeout(resolve, 300));
        
        // REMOVED: fetch('/API/facturation_payments_list.php?...')
        // REPLACED: Get static demo data
        const paiements = mockGetPaymentsList(selectedClientId);
        
        const timeline = document.getElementById('paiementsTimeline');
        if (timeline) {
            const formatDate = (dateStr) => {
                const d = new Date(dateStr);
                return d.toLocaleDateString('fr-FR');
            };
            
            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
            };
            
            const getStatutBadge = (statut) => {
                const badges = {
                    'en_cours': '<span class="badge badge-warning">EN COURS</span>',
                    'recu': '<span class="badge badge-success">Reçu</span>',
                    'refuse': '<span class="badge badge-danger">Refusé</span>',
                    'annule': '<span class="badge badge-secondary">Annulé</span>'
                };
                return badges[statut] || badges['en_cours'];
            };
            
            let timelineHtml = paiements.map(paiement => {
                let modeText = paiement.mode_paiement;
                if (paiement.reference) {
                    modeText += ` - Réf: ${paiement.reference}`;
                }
                
                return `
                    <div class="timeline-item">
                        <div class="timeline-date">${formatDate(paiement.date_paiement)}</div>
                        <div class="timeline-content">
                            <div class="timeline-amount">${formatCurrency(paiement.montant)}</div>
                            <div class="timeline-mode">${modeText}</div>
                            ${paiement.commentaire ? `<div class="timeline-comment">${paiement.commentaire}</div>` : ''}
                            <div class="timeline-statut">${getStatutBadge(paiement.statut)}</div>
                        </div>
                    </div>
                `;
            }).join('');
            
            if (paiements.length === 0) {
                timelineHtml = '<div class="timeline-item"><div class="timeline-content">Aucun paiement enregistré</div></div>';
            }
            
            timeline.innerHTML = timelineHtml;
        }
        
        const summary = document.getElementById('paiementSummary');
        if (summary) {
            // REMOVED: fetch('/API/facturation_summary.php?...')
            // REPLACED: Get static summary data
            const summaryData = mockGetSummaryData(selectedClientId);
            
            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
            };
            
            if (summaryData.montant_non_paye > 0) {
                summary.innerHTML = `
                    <div class="summary-row">
                        <span>Total à facturer :</span>
                        <strong>${formatCurrency(summaryData.total_a_facturer)}</strong>
                    </div>
                    <div class="summary-row">
                        <span>Total payé :</span>
                        <strong class="text-success">${formatCurrency(summaryData.montant_paye)}</strong>
                    </div>
                    <div class="summary-row">
                        <span>Solde restant :</span>
                        <strong class="text-warning">${formatCurrency(summaryData.montant_non_paye)}</strong>
                    </div>
                    <div class="summary-row">
                        <span>Statut paiement :</span>
                        <span class="badge badge-warning">PARTIELLEMENT PAYÉ</span>
                    </div>
                `;
            } else {
                summary.innerHTML = `
                    <div class="paiement-summary-empty">
                        <p>Toutes les factures sont payées</p>
                    </div>
                `;
            }
        }
        
        const paiementsList = document.getElementById('paiementsList');
        if (paiementsList) {
            const formatDate = (dateStr) => {
                const d = new Date(dateStr);
                return d.toLocaleDateString('fr-FR');
            };
            
            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
            };
            
            const getStatutBadge = (statut) => {
                const badges = {
                    'en_cours': '<span class="badge badge-warning">EN COURS</span>',
                    'recu': '<span class="badge badge-success">Reçu</span>',
                    'refuse': '<span class="badge badge-danger">Refusé</span>',
                    'annule': '<span class="badge badge-secondary">Annulé</span>'
                };
                return badges[statut] || badges['en_cours'];
            };
            
            let paiementsHtml = paiements.slice(0, 5).map(paiement => {
                const userName = paiement.created_by_prenom && paiement.created_by_nom 
                    ? `${paiement.created_by_prenom} ${paiement.created_by_nom}`
                    : 'Admin CCComputer';
                
                return `
                    <div class="paiement-item">
                        <div class="paiement-date">${formatDate(paiement.date_paiement)}</div>
                        <div class="paiement-amount">${formatCurrency(paiement.montant)}</div>
                        <div class="paiement-user">${userName}</div>
                        <div class="paiement-mode">${paiement.mode_paiement}</div>
                        <div class="paiement-etat">${getStatutBadge(paiement.statut)}</div>
                    </div>
                `;
            }).join('');
            
            if (paiements.length === 0) {
                paiementsHtml = '<div class="paiement-item"><div style="text-align: center; padding: 1rem;">Aucun paiement enregistré</div></div>';
            }
            
            paiementsList.innerHTML = paiementsHtml;
        }
    } catch (error) {
        console.error('Erreur chargement paiements:', error);
        const timeline = document.getElementById('paiementsTimeline');
        if (timeline) {
            timeline.innerHTML = '<div class="timeline-item"><div class="timeline-content" style="color: #ef4444;">Erreur lors du chargement des paiements</div></div>';
        }
    }
}

// ==================
// EXPORT FUNCTIONS (simplified for demo)
// ==================
function initExportClientSearch() {
    const searchInput = document.getElementById('exportClientSearch');
    const dropdown = document.getElementById('exportClientSearchDropdown');
    const selectedDisplay = document.getElementById('exportSelectedClientDisplay');
    const btnRemove = document.getElementById('btnRemoveExportClient');
    
    if (!searchInput || !dropdown || !selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    let searchTimeout = null;
    
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            dropdown.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            const clients = mockSearchClients(query, 10);
            
            dropdown.innerHTML = '';
            
            if (clients.length === 0) {
                dropdown.innerHTML = '<div class="dropdown-item empty-state">Aucun client trouvé</div>';
            } else {
                clients.forEach(client => {
                    const item = document.createElement('div');
                    item.className = 'dropdown-item';
                    
                    const name = highlightMatch(client.raison_sociale, query);
                    const details = `${client.prenom} ${client.nom} • ${client.reference}`;
                    
                    item.innerHTML = `
                        <div class="dropdown-item-main">${name}</div>
                        <div class="dropdown-item-sub">${details}</div>
                    `;
                    
                    item.addEventListener('click', () => {
                        exportSelectedClientId = client.id;
                        selectedName.textContent = client.raison_sociale;
                        selectedDisplay.style.display = 'flex';
                        document.getElementById('exportClientSearch').value = '';
                        dropdown.style.display = 'none';
                    });
                    
                    dropdown.appendChild(item);
                });
            }
            
            dropdown.style.display = 'block';
        }, 200);
    });
}

let exportSelectedClientId = null;

// ==================
// INITIALIZATION
// ==================
document.addEventListener('DOMContentLoaded', () => {
    initClientSearch();
    initExportClientSearch();
    initButtonGroups();
    updateExportPeriodControls();
    initDefaultPeriodValues();
    updateGranularityControls();
    initConsumptionChart();
    updateTableConsommation();
    
    const granularityTypeSelect = document.getElementById('chartGranularity');
    if (granularityTypeSelect) {
        granularityTypeSelect.addEventListener('change', () => {
            updateGranularityControls();
            reloadBillingData();
        });
    }
    
    const periodControls = ['chartYear', 'chartMonthYear', 'chartMonth'];
    periodControls.forEach(controlId => {
        const control = document.getElementById(controlId);
        if (control) {
            control.addEventListener('change', () => {
                reloadBillingData();
            });
        }
    });
});

// ==================
// TAB MANAGEMENT
// ==================
const tabButtons = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');

tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        const targetTab = btn.dataset.tab;
        
        tabButtons.forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        tabContents.forEach(c => {
            c.classList.remove('active');
        });
        
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        document.getElementById(`tab-${targetTab}`).classList.add('active');
        
        if (targetTab === 'paiements' && selectedClientId) {
            updatePaiementsDisplay();
        }
    });
});

// ==================
// EXPORT MODAL FUNCTIONS (simplified)
// ==================
function initButtonGroups() {
    // Button groups for export modal
    const periodButtons = document.querySelectorAll('#btnPeriodYear, #btnPeriodMonth');
    periodButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            periodButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('exportPeriodType').value = btn.dataset.value;
            updateExportPeriodControls();
        });
    });
}

function updateExportPeriodControls() {
    const periodType = document.getElementById('exportPeriodType')?.value;
    if (!periodType) return;
    
    const yearControls = document.getElementById('exportYearControls');
    const monthControls = document.getElementById('exportMonthControls');
    
    if (periodType === 'year') {
        if (yearControls) yearControls.style.display = 'block';
        if (monthControls) monthControls.style.display = 'none';
    } else {
        if (yearControls) yearControls.style.display = 'none';
        if (monthControls) monthControls.style.display = 'block';
    }
}

// ==================
// INVOICE PREVIEW MODAL
// ==================
function openFactureApercu() {
    const modal = document.getElementById('modalFactureApercu');
    if (!modal) return;
    
    const factureNum = document.getElementById('factureNum')?.textContent || 'Facture #2025-001';
    const facturePeriod = document.getElementById('facturePeriod')?.textContent.replace('Période : ', '') || '20/01/2025 - 07/02/2025';
    const factureMontantTTC = document.getElementById('factureMontantTTC')?.textContent || '845,20 €';
    
    const parseFrenchNumber = (text) => {
        return parseFloat(text.replace(/\s/g, '').replace(',', '.').replace(/[^\d.]/g, ''));
    };
    
    const montantTTC = parseFrenchNumber(factureMontantTTC);
    const montantHT = montantTTC / 1.20;
    const montantTVA = montantTTC - montantHT;
    
    const formatFrenchNumber = (num) => {
        return num.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
    };
    
    const now = new Date();
    const dateFacture = now.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    
    const facturePreviewNum = document.getElementById('facturePreviewNum');
    const facturePreviewDate = document.getElementById('facturePreviewDate');
    const facturePreviewPeriod = document.getElementById('facturePreviewPeriod');
    const facturePreviewHT = document.getElementById('facturePreviewHT');
    const facturePreviewTVA = document.getElementById('facturePreviewTVA');
    const facturePreviewTTC = document.getElementById('facturePreviewTTC');
    
    if (facturePreviewNum) facturePreviewNum.textContent = factureNum;
    if (facturePreviewDate) facturePreviewDate.textContent = `Date : ${dateFacture}`;
    if (facturePreviewPeriod) {
        facturePreviewPeriod.innerHTML = `<strong>Période de consommation :</strong> ${facturePeriod}`;
    }
    if (facturePreviewHT) facturePreviewHT.textContent = formatFrenchNumber(montantHT);
    if (facturePreviewTVA) facturePreviewTVA.textContent = formatFrenchNumber(montantTVA);
    if (facturePreviewTTC) facturePreviewTTC.textContent = formatFrenchNumber(montantTTC);
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeFactureApercu() {
    const modal = document.getElementById('modalFactureApercu');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

document.getElementById('btnOuvrirFacture')?.addEventListener('click', openFactureApercu);
document.getElementById('btnGenererFacture')?.addEventListener('click', genererFacture);
document.getElementById('btnCloseFactureApercu')?.addEventListener('click', closeFactureApercu);

const modalFactureApercu = document.getElementById('modalFactureApercu');
if (modalFactureApercu) {
    modalFactureApercu.addEventListener('click', (e) => {
        if (e.target.id === 'modalFactureApercu') {
            closeFactureApercu();
        }
    });
}

