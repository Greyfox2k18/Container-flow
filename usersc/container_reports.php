<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/container_functions.php';

if (!securePage($_SERVER['PHP_SELF'])) { die(); }
$csrf = Token::generate();
?>
<style>
.rp-wrap{max-width:1200px;margin:0 auto;padding:0 0 40px;}
.rp-header{display:flex;align-items:center;justify-content:space-between;padding:18px 0 16px;flex-wrap:wrap;gap:12px;}
.rp-header h2{margin:0;font-size:22px;}
.rp-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.rp-preset{padding:6px 14px;border:1px solid #dee2e6;border-radius:20px;font-size:13px;cursor:pointer;background:#fff;color:#374151;transition:all .15s;white-space:nowrap;}
.rp-preset:hover{border-color:#0067b8;color:#0067b8;}
.rp-preset.active{background:#0067b8;border-color:#0067b8;color:#fff;font-weight:600;}
.rp-date-sep{color:#9ca3af;font-size:13px;}
.rp-custom-wrap{display:flex;align-items:center;gap:6px;}
.rp-custom-wrap input{height:32px;border:1px solid #dee2e6;border-radius:6px;padding:0 8px;font-size:13px;}
.rp-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.rp-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.rp-tile-val{font-size:30px;font-weight:700;line-height:1;margin-bottom:4px;}
.rp-tile-label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.rp-tile-sub{font-size:12px;margin-top:4px;}.trend-up{color:#10b981;}.trend-dn{color:#ef4444;}
.rp-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
.rp-grid-wide{margin-bottom:20px;}
.rp-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px 22px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.rp-card h4{margin:0 0 16px;font-size:14px;color:#374151;font-weight:700;}
.rp-no-data{text-align:center;padding:30px 0;color:#9ca3af;font-size:13px;}
#rp-loading{position:fixed;top:0;left:0;right:0;height:3px;background:#0067b8;z-index:9999;transition:opacity .3s;animation:rp-progress 1.2s ease infinite;}
@keyframes rp-progress{0%{transform:scaleX(0);transform-origin:left}50%{transform:scaleX(.7);transform-origin:left}100%{transform:scaleX(1);transform-origin:left;opacity:0}}
/* Client table */
.rp-client-table{width:100%;border-collapse:collapse;font-size:13px;}
.rp-client-table th{text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:700;border-bottom:2px solid #e5e7eb;background:#f9fafb;}
.rp-client-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.rp-client-table tr:hover td{background:#f9fafb;}
.rp-bar-wrap{width:80px;height:6px;background:#e5e7eb;border-radius:3px;display:inline-block;vertical-align:middle;}
.rp-bar{height:6px;border-radius:3px;background:#0067b8;}
@media(max-width:700px){.rp-grid{grid-template-columns:1fr;}.rp-tiles{grid-template-columns:repeat(2,1fr);}}
</style>

<div id="page-wrapper"><div class="container-fluid"><div class="rp-wrap">

<div class="rp-header">
    <h2><i class="fa fa-bar-chart" style="color:#0067b8;margin-right:8px;"></i>Reporting Dashboard</h2>
    <a href="container_dashboard.php" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Dashboard</a>
</div>

<div class="rp-controls">
    <button class="rp-preset active" data-preset="30days">Last 30 days</button>
    <button class="rp-preset" data-preset="7days">Last 7 days</button>
    <button class="rp-preset" data-preset="90days">Last 90 days</button>
    <button class="rp-preset" data-preset="year">This year</button>
    <button class="rp-preset" data-preset="all">All time</button>
    <span class="rp-date-sep">|</span>
    <div class="rp-custom-wrap">
        <input type="date" id="rpFrom"><span class="rp-date-sep">&rarr;</span>
        <input type="date" id="rpTo">
        <button class="btn btn-default btn-sm" id="rpApplyCustom">Apply</button>
    </div>
</div>

<div id="rp-loading" style="display:none;"></div>

<!-- Stat tiles -->
<div class="rp-tiles" id="rpTiles">
    <div class="rp-tile"><div class="rp-tile-val" id="tile-total" style="color:#0067b8;">—</div><div class="rp-tile-label">Total Containers</div><div class="rp-tile-sub" id="tile-trend"></div></div>
    <div class="rp-tile"><div class="rp-tile-val" id="tile-reviewed" style="color:#10b981;">—</div><div class="rp-tile-label">Reviewed</div></div>
    <div class="rp-tile"><div class="rp-tile-val" id="tile-awaiting" style="color:#6d28d9;">—</div><div class="rp-tile-label">Awaiting Review</div></div>
    <div class="rp-tile"><div class="rp-tile-val" id="tile-turnaround" style="color:#f59e0b;">—</div><div class="rp-tile-label">Avg Turnaround</div><div class="rp-tile-sub" id="tile-turnaround-sub" style="color:#9ca3af;"></div></div>
    <div class="rp-tile"><div class="rp-tile-val" id="tile-inbound" style="color:#3b82f6;">—</div><div class="rp-tile-label">Inbound</div></div>
    <div class="rp-tile"><div class="rp-tile-val" id="tile-outbound" style="color:#10b981;">—</div><div class="rp-tile-label">Outbound</div></div>
</div>

<!-- Volume -->
<div class="rp-grid-wide">
    <div class="rp-card">
        <h4><i class="fa fa-line-chart" style="color:#0067b8;margin-right:6px;"></i>Volume Over Time</h4>
        <canvas id="chartVolume" height="80"></canvas>
        <div class="rp-no-data" id="noVolume" style="display:none;">No data for this period</div>
    </div>
</div>

<!-- Status + Type -->
<div class="rp-grid">
    <div class="rp-card">
        <h4><i class="fa fa-pie-chart" style="color:#6d28d9;margin-right:6px;"></i>Status Breakdown</h4>
        <div style="max-width:280px;margin:0 auto;"><canvas id="chartStatus"></canvas></div>
        <div class="rp-no-data" id="noStatus" style="display:none;">No data</div>
    </div>
    <div class="rp-card">
        <h4><i class="fa fa-exchange" style="color:#0067b8;margin-right:6px;"></i>Inbound vs Outbound</h4>
        <div style="max-width:280px;margin:0 auto;"><canvas id="chartType"></canvas></div>
        <div class="rp-no-data" id="noType" style="display:none;">No data</div>
    </div>
</div>

<!-- Carriers -->
<div class="rp-grid">
    <div class="rp-card">
        <h4><i class="fa fa-truck" style="color:#0067b8;margin-right:6px;"></i>Top Carriers</h4>
        <canvas id="chartCarriers"></canvas>
        <div class="rp-no-data" id="noCarriers" style="display:none;">No data</div>
    </div>
    <div class="rp-card">
        <h4><i class="fa fa-clock-o" style="color:#f59e0b;margin-right:6px;"></i>Avg Turnaround by Client <small style="font-weight:400;color:#9ca3af;">(days)</small></h4>
        <canvas id="chartTurnaround"></canvas>
        <div class="rp-no-data" id="noTurnaround" style="display:none;">No reviewed containers</div>
    </div>
</div>

<!-- By Client table -->
<div class="rp-grid-wide">
    <div class="rp-card">
        <h4><i class="fa fa-building-o" style="color:#0067b8;margin-right:6px;"></i>By Client</h4>
        <div id="clientTableWrap">
            <div class="rp-no-data">Loading...</div>
        </div>
    </div>
</div>

</div></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
(function(){
'use strict';
var BASE = '<?php echo $us_url_root; ?>';
Chart.defaults.font.family = 'Arial,sans-serif';
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7280';
var STATUS_COLORS = {pending:'#f59e0b',in_progress:'#3b82f6',completed:'#6d28d9',reviewed:'#10b981'};
var STATUS_LABELS = {pending:'Pending',in_progress:'In Progress',completed:'Awaiting Review',reviewed:'Reviewed'};
var charts = {};
function destroyChart(id){ if(charts[id]){ charts[id].destroy(); delete charts[id]; } }

function el(id){ return document.getElementById(id); }
function set(id,v){ var e=el(id); if(e) e.textContent=v; }

var currentPreset='30days', currentFrom='', currentTo='';

function load(preset,from,to){
    currentPreset=preset||currentPreset; currentFrom=from||''; currentTo=to||'';
    var loading=el('rp-loading'); loading.style.display='block'; loading.style.opacity='1';
    var params=new URLSearchParams({preset:currentPreset});
    if(currentFrom) params.set('from',currentFrom);
    if(currentTo)   params.set('to',currentTo);
    fetch(BASE+'usersc/ajax/get_reports_data.php?'+params)
        .then(function(r){return r.json();})
        .then(function(d){ loading.style.opacity='0'; setTimeout(function(){loading.style.display='none';},300); if(!d.success){alert(d.message||'Error');return;} render(d); })
        .catch(function(e){ loading.style.display='none'; console.error(e); });
}

function render(d){
    var s=d.summary;
    set('tile-total',  s.total||0);
    set('tile-reviewed', s.reviewed||0);
    set('tile-awaiting', s.completed||0);
    set('tile-inbound',  s.inbound||0);
    set('tile-outbound', s.outbound||0);
    if(s.avg_turnaround_days!==null&&s.avg_turnaround_days!==undefined){
        set('tile-turnaround', s.avg_turnaround_days+'d');
        set('tile-turnaround-sub','from '+(s.reviewed_with_timing||0)+' reviewed');
    } else { set('tile-turnaround','—'); set('tile-turnaround-sub','no reviewed containers'); }
    var trendEl=el('tile-trend');
    if(d.trend_pct!==null&&d.trend_pct!==undefined){
        var sign=d.trend_pct>=0?'+':''; trendEl.innerHTML='<span class="'+(d.trend_pct>=0?'trend-up':'trend-dn')+'">'+sign+d.trend_pct+'% vs prior</span>';
    } else trendEl.textContent='';

    // Volume
    destroyChart('vol');
    var volEl=el('chartVolume');
    if(!d.volume||!d.volume.length){ volEl.style.display='none'; el('noVolume').style.display='block'; }
    else {
        volEl.style.display=''; el('noVolume').style.display='none';
        charts['vol']=new Chart(volEl,{type:'line',data:{
            labels:d.volume.map(function(v){return v.date;}),
            datasets:[{label:d.use_weekly?'Weekly':'Daily',data:d.volume.map(function(v){return v.count;}),borderColor:'#0067b8',backgroundColor:'rgba(0,103,184,.08)',borderWidth:2,fill:true,tension:.3,pointRadius:d.volume.length>60?0:4}]
        },options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:12,maxRotation:0}},y:{beginAtZero:true,ticks:{precision:0}}}}});
    }

    // Status doughnut
    destroyChart('st');
    var stEl=el('chartStatus');
    if(!d.by_status||!d.by_status.length){ stEl.style.display='none'; el('noStatus').style.display='block'; }
    else { stEl.style.display=''; el('noStatus').style.display='none';
        charts['st']=new Chart(stEl,{type:'doughnut',data:{labels:d.by_status.map(function(r){return STATUS_LABELS[r.status]||r.status;}),datasets:[{data:d.by_status.map(function(r){return r.count;}),backgroundColor:d.by_status.map(function(r){return STATUS_COLORS[r.status]||'#9ca3af';}),borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},cutout:'60%'}});
    }

    // Type doughnut
    destroyChart('ty');
    var tyEl=el('chartType');
    if(!d.by_type||!d.by_type.length){ tyEl.style.display='none'; el('noType').style.display='block'; }
    else { tyEl.style.display=''; el('noType').style.display='none';
        charts['ty']=new Chart(tyEl,{type:'doughnut',data:{labels:d.by_type.map(function(r){return r.type.charAt(0).toUpperCase()+r.type.slice(1);}),datasets:[{data:d.by_type.map(function(r){return r.count;}),backgroundColor:d.by_type.map(function(r){return r.type==='inbound'?'#0067b8':'#10b981';}),borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,plugins:{legend:{position:'bottom'}},cutout:'60%'}});
    }

    // Carriers
    destroyChart('ca');
    var caEl=el('chartCarriers');
    if(!d.by_carrier||!d.by_carrier.length){ caEl.style.display='none'; el('noCarriers').style.display='block'; }
    else { caEl.style.display=''; el('noCarriers').style.display='none';
        var caD=d.by_carrier.slice().reverse(); caEl.height=Math.max(180,caD.length*30);
        charts['ca']=new Chart(caEl,{type:'bar',data:{labels:caD.map(function(r){return r.carrier;}),datasets:[{label:'Containers',data:caD.map(function(r){return r.count;}),backgroundColor:'rgba(16,185,129,.7)',borderColor:'#10b981',borderWidth:1,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}},y:{grid:{display:false}}}}});
    }

    // Turnaround by client
    destroyChart('ta');
    var taEl=el('chartTurnaround');
    if(!d.turnaround_by_client||!d.turnaround_by_client.length){ taEl.style.display='none'; el('noTurnaround').style.display='block'; }
    else { taEl.style.display=''; el('noTurnaround').style.display='none';
        var taD=d.turnaround_by_client.slice().reverse(); taEl.height=Math.max(120,taD.length*32);
        charts['ta']=new Chart(taEl,{type:'bar',data:{labels:taD.map(function(r){return r.customer+' ('+r.count+')';}),datasets:[{label:'Avg days',data:taD.map(function(r){return r.avg_days;}),backgroundColor:'rgba(245,158,11,.7)',borderColor:'#f59e0b',borderWidth:1,borderRadius:4}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(ctx){return ctx.raw+' days avg';}}}},scales:{x:{beginAtZero:true,title:{display:true,text:'Days'}},y:{grid:{display:false}}}}});
    }

    // By-client table
    var wrap = el('clientTableWrap');
    if (!d.client_table || !d.client_table.length) {
        wrap.innerHTML = '<div class="rp-no-data">No data for this period</div>';
    } else {
        var html = '<div style="overflow-x:auto;"><table class="rp-client-table">';
        html += '<thead><tr><th>Client</th><th>Total</th><th>Reviewed</th><th>Active</th><th>Inbound</th><th>Outbound</th><th>Completion</th><th>Avg Turnaround</th><th>Last Container</th></tr></thead><tbody>';
        d.client_table.forEach(function(r) {
            var barW = Math.round(r.completion_rate);
            var ta   = r.avg_turnaround_days !== null ? r.avg_turnaround_days + 'd' : '—';
            var last = r.last_container ? r.last_container.substring(0,10) : '—';
            html += '<tr>';
            html += '<td style="font-weight:600;">'+escHTML(r.client)+'</td>';
            html += '<td>'+r.total+'</td>';
            html += '<td style="color:#10b981;font-weight:600;">'+r.reviewed+'</td>';
            html += '<td style="color:#3b82f6;">'+r.open+'</td>';
            html += '<td>'+r.inbound+'</td>';
            html += '<td>'+r.outbound+'</td>';
            html += '<td><div style="display:flex;align-items:center;gap:8px;"><div class="rp-bar-wrap"><div class="rp-bar" style="width:'+barW+'%;"></div></div><span style="font-size:12px;color:#6b7280;">'+r.completion_rate+'%</span></div></td>';
            html += '<td style="color:'+(r.avg_turnaround_days!==null&&r.avg_turnaround_days>3?'#f59e0b':'#374151')+';">'+ta+'</td>';
            html += '<td style="color:#9ca3af;font-size:12px;">'+last+'</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    }
}

function escHTML(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// Controls
document.querySelectorAll('.rp-preset').forEach(function(btn){
    btn.addEventListener('click',function(){
        document.querySelectorAll('.rp-preset').forEach(function(b){b.classList.remove('active');});
        this.classList.add('active');
        el('rpFrom').value=''; el('rpTo').value='';
        load(this.dataset.preset,'','');
    });
});
el('rpApplyCustom').addEventListener('click',function(){
    var from=el('rpFrom').value, to=el('rpTo').value;
    if(!from||!to){alert('Please set both dates.');return;}
    if(from>to){alert('Start must be before end.');return;}
    document.querySelectorAll('.rp-preset').forEach(function(b){b.classList.remove('active');});
    load('custom',from,to);
});
load('30days');
})();
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
