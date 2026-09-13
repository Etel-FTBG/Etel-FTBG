// js/admin/admin-charts.js
// Extracted from admin.js

// 📈 Chart.js Instances
let revenueOrdersChart = null;
let statusDistChart = null;
let zoneRevChart = null;

// 📈 RENDER DASHBOARD CHARTS
function renderDashboardCharts(orders) {
    try {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded');
            document.querySelectorAll('.chart-card canvas').forEach(function(c) {
                var p = c.parentNode;
                p.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#aaa;font-size:12px">Chart.js unavailable</div>';
            });
            return;
        }
        // 1. Revenue & Orders Trend (Last 30 Days)
        const dailyData = {};
        const today = new Date();
        for (let i = 29; i >= 0; i--) {
            const d = new Date(today);
            d.setDate(today.getDate() - i);
            const dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            dailyData[d.toDateString()] = { label: dateStr, revenue: 0, count: 0 };
        }

        orders.forEach(o => {
            if (!o.placed_at) return;
            const oDate = new Date(o.placed_at);
            const key = oDate.toDateString();
            if (dailyData[key]) {
                dailyData[key].count += 1;
                if (o.status === 'delivered') {
                    dailyData[key].revenue += parseFloat(o.fare_total || 0);
                }
            }
        });

        const labels = Object.values(dailyData).map(x => x.label);
        const revenues = Object.values(dailyData).map(x => x.revenue);
        const counts = Object.values(dailyData).map(x => x.count);

        const ctx1 = document.getElementById('chart-revenue-orders').getContext('2d');
        if (revenueOrdersChart) revenueOrdersChart.destroy();
        revenueOrdersChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Revenue (ETB)',
                        data: revenues,
                        borderColor: '#D4AF37',
                        backgroundColor: 'rgba(212, 175, 55, 0.1)',
                        yAxisID: 'y-revenue',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Orders Count',
                        data: counts,
                        borderColor: '#4ECDC4',
                        backgroundColor: 'transparent',
                        yAxisID: 'y-count',
                        tension: 0.4,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    'y-revenue': {
                        type: 'linear',
                        position: 'left',
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        title: { display: true, text: 'Revenue (ETB)', font: { family: 'Poppins', size: 11 } }
                    },
                    'y-count': {
                        type: 'linear',
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Order Count', font: { family: 'Poppins', size: 11 } }
                    }
                },
                plugins: {
                    legend: { labels: { font: { family: 'Poppins', size: 11 } } }
                }
            }
        });

        // 2. Status Distribution
        const statusCounts = {};
        orders.forEach(o => {
            const s = o.status || 'pending';
            statusCounts[s] = (statusCounts[s] || 0) + 1;
        });
        
        const ctx2 = document.getElementById('chart-status-dist').getContext('2d');
        if (statusDistChart) statusDistChart.destroy();
        
        const statusLabels = Object.keys(statusCounts).map(s => s.replace(/_/g, ' ').toUpperCase());
        const statusValues = Object.values(statusCounts);
        
        const statusColors = {
            'pending': '#FF7A45',
            'assigned': '#4ECDC4',
            'accepted': '#3498db',
            'picked_up': '#9B59B6',
            'in_transit': '#8e44ad',
            'delivered': '#2ECC71',
            'cancelled': '#E63946'
        };
        const bgColors = Object.keys(statusCounts).map(s => statusColors[s] || '#7f8c8d');

        statusDistChart = new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusValues,
                    backgroundColor: bgColors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { font: { family: 'Poppins', size: 11 } } }
                }
            }
        });

        // 3. Revenue by Delivery Zone (ETB)
        const zoneRevenue = {};
        orders.forEach(o => {
            if (o.status !== 'delivered') return;
            const zone = o.to_zone || 'Unknown';
            zoneRevenue[zone] = (zoneRevenue[zone] || 0) + parseFloat(o.fare_total || 0);
        });

        const sortedZones = Object.entries(zoneRevenue).sort((a, b) => b[1] - a[1]).slice(0, 5);
        const zoneLabels = sortedZones.map(x => x[0]);
        const zoneValues = sortedZones.map(x => x[1]);

        const ctx3 = document.getElementById('chart-zone-rev').getContext('2d');
        if (zoneRevChart) zoneRevChart.destroy();
        
        zoneRevChart = new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: zoneLabels,
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: zoneValues,
                    backgroundColor: 'rgba(212, 175, 55, 0.85)',
                    borderColor: '#D4AF37',
                    borderWidth: 1.5,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { font: { family: 'Poppins', size: 11 } } },
                    y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { family: 'Poppins', size: 11 } } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    } catch (err) {
        console.error("Error drawing charts:", err);
    }
}

window.renderDashboardCharts = renderDashboardCharts;
