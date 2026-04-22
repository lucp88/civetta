<?php
$detailAccentColor = $detailAccentColor ?? '#3d6b3d';
$detailAccentColorDark = $detailAccentColorDark ?? '#2d4a2d';
$detailLinkColor = $detailLinkColor ?? $detailAccentColor;
?>

<style>
    .detail-modal { max-width: 600px; }
    .detail-modal .modal-header {
        background: linear-gradient(135deg, <?= $detailAccentColorDark ?>, <?= $detailAccentColor ?>);
    }
    .detail-modal .modal-body { padding: 1.25rem; }
    .detail-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 768px) {
        .detail-modal { max-width: 100%; }
        .detail-modal .modal-body { padding: 1rem; }
        .detail-grid { gap: 0.75rem; margin-bottom: 1rem; }
        .detail-section { margin-bottom: 1rem; }
        .od-product-list { padding: 0.5rem; }
        .od-product-row { font-size: 0.9rem; padding: 0.4rem 0; }
        .status-flow { gap: 0.3rem; }
        .status-step { padding: 0.3rem 0.5rem; font-size: 0.7rem; }
        .status-arrow { font-size: 0.8rem; }
        .od-modal-actions { padding: 0.75rem 1rem; gap: 0.5rem; }
        .od-btn { padding: 0.5rem 0.9rem; font-size: 0.8rem; }
    }
    @media (max-width: 500px) {
        .detail-grid { grid-template-columns: 1fr; }
        .status-flow { flex-direction: column; align-items: stretch; }
        .status-step { justify-content: center; }
        .status-arrow { text-align: center; transform: rotate(90deg); font-size: 0.7rem; margin: -0.2rem 0; }
        .od-modal-actions { flex-direction: column; }
        .od-btn { justify-content: center; width: 100%; }
        .notes-box { font-size: 0.85rem; padding: 0.6rem; }
    }
    .detail-item label {
        display: block;
        font-size: 0.75rem;
        color: #888;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }
    .detail-item .value {
        color: #333;
        font-weight: 500;
    }
    .detail-item .value a {
        color: <?= $detailLinkColor ?>;
        text-decoration: none;
    }
    .detail-item .value a:hover { text-decoration: underline; }
    .detail-section {
        margin-bottom: 1.5rem;
    }
    .detail-section h4 {
        font-size: 0.9rem;
        color: <?= $detailAccentColorDark ?>;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #eee;
    }
    .od-product-list {
        background: #faf8f5;
        border-radius: 8px;
        padding: 0.75rem;
    }
    .od-product-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #eee;
    }
    .od-product-row:last-child { border-bottom: none; }
    .od-product-qty {
        font-weight: 600;
        color: <?= $detailAccentColor ?>;
        margin-right: 0.5rem;
    }
    .notes-box {
        background: #fffbe6;
        border: 1px solid #ffe58f;
        border-radius: 6px;
        padding: 0.75rem;
        font-size: 0.9rem;
    }
    .status-flow {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }
    .status-step {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.4rem 0.7rem;
        background: #f0f0f0;
        border-radius: 20px;
        font-size: 0.75rem;
        color: #999;
    }
    .status-step.active {
        background: #d1e7dd;
        color: #0f5132;
    }
    .status-step.current {
        background: linear-gradient(135deg, <?= $detailAccentColor ?>, <?= $detailAccentColorDark ?>);
        color: white;
    }
    .status-arrow { color: #ccc; }
    .od-modal-actions {
        padding: 1rem 1.25rem;
        border-top: 1px solid #eee;
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .od-btn {
        padding: 0.6rem 1.2rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.9rem;
        border: none;
    }
    .od-btn-route {
        background: linear-gradient(135deg, #2196f3, #1976d2);
        color: white;
    }
    .od-btn-outline {
        background: white;
        border: 2px solid <?= $detailAccentColor ?>;
        color: <?= $detailAccentColor ?>;
    }
    .od-btn-outline:hover { background: #f5f2ed; }
</style>

<div class="modal-overlay" id="orderDetailModal">
    <div class="modal detail-modal">
        <div class="modal-header">
            <h3><i class="bi bi-box"></i> Bestelling <span id="od-orderId"></span></h3>
            <button class="modal-close" onclick="closeOrderModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Bedrijf</label>
                    <div class="value" id="od-company"></div>
                </div>
                <div class="detail-item">
                    <label>Contactpersoon</label>
                    <div class="value" id="od-contact"></div>
                </div>
                <div class="detail-item">
                    <label>Telefoon</label>
                    <div class="value"><a id="od-phone" href=""></a></div>
                </div>
                <div class="detail-item">
                    <label>E-mail</label>
                    <div class="value"><a id="od-email" href=""></a></div>
                </div>
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <label>Leveradres</label>
                    <div class="value" id="od-address"></div>
                </div>
            </div>

            <div class="detail-section">
                <h4>Status</h4>
                <div class="status-flow" id="od-status"></div>
            </div>

            <div class="detail-section">
                <h4>Producten</h4>
                <div class="od-product-list" id="od-products"></div>
            </div>

            <div class="detail-section" id="od-notesSection" style="display:none;">
                <h4>Opmerkingen</h4>
                <div class="notes-box" id="od-notes"></div>
            </div>

            <div class="detail-grid" style="margin-top: 1rem;">
                <div class="detail-item">
                    <label>Betaalmethode</label>
                    <div class="value" id="od-paymentType"></div>
                </div>
                <div class="detail-item">
                    <label>Totaalbedrag</label>
                    <div class="value" style="font-size: 1.2rem; color: <?= $detailAccentColorDark ?>;" id="od-total"></div>
                </div>
            </div>
        </div>
        <div class="od-modal-actions">
            <a id="od-routeBtn" href="" target="_blank" class="od-btn od-btn-route">
                <i class="bi bi-geo-alt"></i> Navigeren
            </a>
            <a id="od-phoneBtn" href="" class="od-btn od-btn-outline">
                <i class="bi bi-telephone"></i> Bellen
            </a>
            <a id="od-bestelbonBtn" href="" target="_blank" class="od-btn od-btn-outline">
                <i class="bi bi-file-earmark-text"></i> Bestelbon
            </a>
        </div>
    </div>
</div>

<script>
function showOrderDetail(order) {
    document.getElementById('od-orderId').textContent = '#' + order.id;
    document.getElementById('od-company').textContent = order.bedrijfsnaam;
    document.getElementById('od-contact').textContent = order.contactpersoon || '-';

    const phoneEl = document.getElementById('od-phone');
    phoneEl.textContent = order.telefoon || '-';
    phoneEl.href = order.telefoon ? 'tel:' + order.telefoon : '#';

    const emailEl = document.getElementById('od-email');
    emailEl.textContent = order.email || '-';
    emailEl.href = order.email ? 'mailto:' + order.email : '#';

    document.getElementById('od-address').textContent = order.full_delivery_address;

    document.getElementById('od-status').innerHTML = `
        <div class="status-step ${order.delivery_status === 'geplaatst' ? 'current' : 'active'}">
            <i class="bi bi-check2-circle"></i> Geplaatst
        </div>
        <span class="status-arrow">&rarr;</span>
        <div class="status-step ${order.delivery_status === 'wordt_bereid' ? 'current' : (['onderweg', 'afgeleverd'].includes(order.delivery_status) ? 'active' : '')}">
            <i class="bi bi-fire"></i> Bereiden
        </div>
        <span class="status-arrow">&rarr;</span>
        <div class="status-step ${order.delivery_status === 'onderweg' ? 'current' : (order.delivery_status === 'afgeleverd' ? 'active' : '')}">
            <i class="bi bi-truck"></i> Onderweg
        </div>
        <span class="status-arrow">&rarr;</span>
        <div class="status-step ${order.delivery_status === 'afgeleverd' ? 'current' : ''}">
            <i class="bi bi-house-check"></i> Afgeleverd
        </div>
    `;

    let productsHtml = '';
    order.items.forEach(item => {
        productsHtml += `
            <div class="od-product-row">
                <span><span class="od-product-qty">${item.quantity}x</span> ${escapeHtml(item.product_name)}</span>
                <span>&euro;${(item.quantity * item.unit_price).toFixed(2).replace('.', ',')}</span>
            </div>
        `;
    });
    document.getElementById('od-products').innerHTML = productsHtml;

    if (order.notes) {
        document.getElementById('od-notes').textContent = order.notes;
        document.getElementById('od-notesSection').style.display = '';
    } else {
        document.getElementById('od-notesSection').style.display = 'none';
    }

    const paymentTypes = {
        'ideal': 'iDEAL',
        'mollie_direct': 'iDEAL',
        'factuur': 'Op factuur',
        'invoice': 'Op factuur'
    };
    let paymentText = paymentTypes[order.payment_type] || order.payment_type;
    if (order.payment_status === 'paid') {
        paymentText += ' <span class="status-badge paid">Betaald</span>';
    } else {
        paymentText += ' <span class="status-badge pending">Openstaand</span>';
    }
    document.getElementById('od-paymentType').innerHTML = paymentText;

    document.getElementById('od-total').textContent = '\u20AC' + parseFloat(order.total_amount).toFixed(2).replace('.', ',');

    document.getElementById('od-routeBtn').href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(order.full_delivery_address);
    document.getElementById('od-phoneBtn').href = order.telefoon ? 'tel:' + order.telefoon : '#';
    document.getElementById('od-bestelbonBtn').href = '../../api/bestelbon.php?order_id=' + order.id;

    document.getElementById('orderDetailModal').classList.add('active');
}

function closeOrderModal() {
    document.getElementById('orderDetailModal').classList.remove('active');
}

document.getElementById('orderDetailModal').addEventListener('mousedown', function(e) { this._md = e.target === this; });
document.getElementById('orderDetailModal').addEventListener('click', function(e) { if (e.target === this && this._md) closeOrderModal(); });
</script>
