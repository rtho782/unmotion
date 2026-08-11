(function($) {
  
'use strict';
  
var api='/plugins/unmotion/api.php';
  
var peers=[],seeds=[],latestVms=[],peerHealth={},discoveredPeers=[],replications=[],incomingReplicas=[];

var replicationRpoNotches=[
    {seconds:300,label:'5 min'},{seconds:900,label:'15 min'},{seconds:1800,label:'30 min'},
    {seconds:3600,label:'1 hour'},{seconds:7200,label:'2 hours'},{seconds:14400,label:'4 hours'},
    {seconds:21600,label:'6 hours'},{seconds:28800,label:'8 hours'},{seconds:43200,label:'12 hours'},
    {seconds:86400,label:'24 hours'}
  ];
  
var appSettings= {
    copy_isos_default:true,discovery:true,source_cleanup_default:'unregister',health_poll_seconds:10,debug_logging:false
  }
  ;
  
var activeVmId='',activeVmName='',activePreflight=null,activeWarmSeedId='',activeOperation='migration',activeCloneName='';
var activeReplicationId='',activeReplicationVm=null,activeReplicationPreflight=null;
var activeReplicationPreflightSerial=0;
var activeRecoveryId='',activeRecoveryPerspective='',activeRecoveryPayload=null;
var activeRecoveryPointId='',activeRecoveryCheckpointId='';
var activeRecoveryStatusSerial=0,activeRecoveryStatusRequest=null;
var activeConfirmation=null,activeConfirmationFocus=null;
  var activeMigrateButton=null;
  
var discoveryTimer=null,peerRefreshTimer=null,discoveryInFlight=false,currentLogId='',currentLogType='job',logTimer=null,healthTimer=null;
  
function esc(v) {
    return $('<div>').text(v==null?'':String(v)).html();
    
  }
  
function fmtBytes(v) {
    if(v===null||typeof v==='undefined'||v==='')return 'unknown';
    v=Number(v);
    if(!isFinite(v)||v<0)return 'unknown';
    if(v===0)return '0 B';
    var u=['B','KiB','MiB','GiB','TiB'],i=0;
    while(v>=1024&&i<u.length-1) {
      v/=1024;
      i++;
      
    }
    return (v>=10||i===0?v.toFixed(0):v.toFixed(1))+' '+u[i];
    
  }
  
function fmtRamMib(v) {
    v=Number(v||0);
    return v>=1024?(v/1024).toFixed(v%1024?2:0)+' GiB':v+' MiB';
    
  }

function fmtWhen(v) {
    if(v===null||typeof v==='undefined'||v==='')return '—';
    var d;
    if(typeof v==='number'||/^\d+$/.test(String(v))) {
      var n=Number(v);d=new Date(n<100000000000?n*1000:n);
    } else d=new Date(v);
    if(isNaN(d.getTime()))return String(v);
    return d.toLocaleString();
  }

function fmtDurationSeconds(v) {
    if(v===null||typeof v==='undefined'||v==='')return '—';
    var seconds=Math.max(0,Number(v)||0),parts=[];
    var days=Math.floor(seconds/86400);seconds%=86400;
    var hours=Math.floor(seconds/3600);seconds%=3600;
    var minutes=Math.floor(seconds/60);
    if(days)parts.push(days+'d');
    if(hours)parts.push(hours+'h');
    if(minutes||!parts.length)parts.push(minutes+'m');
    return parts.join(' ');
  }

function epochSeconds(v) {
    if(v===null||typeof v==='undefined'||v==='')return null;
    if(typeof v==='number'||/^\d+$/.test(String(v))){var n=Number(v);return n>100000000000?Math.floor(n/1000):n;}
    var parsed=new Date(v).getTime();return isNaN(parsed)?null:Math.floor(parsed/1000);
  }

function rpoIndex(seconds) {
    seconds=Number(seconds||3600);
    for(var i=0;i<replicationRpoNotches.length;i++)if(replicationRpoNotches[i].seconds===seconds)return i;
    return 3;
  }

function rpoLabel(seconds) {
    return replicationRpoNotches[rpoIndex(seconds)].label;
  }

function replicationRetentionMax(seconds) {
    return Math.min(24,Math.floor(86400/Number(seconds||3600)));
  }
  
function post(action,data) {
    data=data|| {
      
    }
    ;
    data.action=action;
    return $.ajax( {
      url:api,method:'POST',data:data,dataType:'json'
    }
    );
    
  }
  
function setButtonBusy(button,label) {
    var b=$(button);
    if(!b.length)return function(){};
    var original=b.val();
    var n=0;
    b.prop('disabled',true).addClass('unm-button-busy').val(label);
    var timer=setInterval(function(){n=(n+1)%4;b.val(label+Array(n+1).join('.'));},350);
    return function(){clearInterval(timer);b.removeClass('unm-button-busy').val(original).prop('disabled',false);};
  }

  function setSettingsLoading(loading,message) {
    var loader=$('#unm-settings-loading'),content=$('#unm-settings-content');
    if(!loader.length)return;
    if(message)loader.find('.unm-loading-text').text(message);
    loader.toggleClass('hidden',!loading);
    content.toggleClass('unm-loading-hidden',loading);
  }

  function flash(msg,bad) {
    var e=$('#unm-flash');
    e.removeClass('ok error').addClass(bad?'error':'ok').text(msg).show();
    setTimeout(function() {
      e.fadeOut();
      
    }
    ,7000);
    
  }

function requestConfirmation(options) {
    options=options||{};
    if(activeConfirmation)activeConfirmation.resolve(false);
    var deferred=$.Deferred(),modal=$('#unm-confirm-modal');
    activeConfirmation=deferred;
    activeConfirmationFocus=document.activeElement;
    $('#unm-confirm-title').text(options.title||'Confirm action');
    $('#unm-confirm-message').text(options.message||'Continue?');
    $('#unm-confirm-accept').val(options.acceptLabel||'Continue');
    modal.removeClass('unm-confirm-danger unm-confirm-warning').addClass(options.tone==='danger'?'unm-confirm-danger':(options.tone==='warning'?'unm-confirm-warning':''));
    modal.addClass('open').attr('aria-hidden','false');
    setTimeout(function(){$('#unm-confirm-cancel').trigger('focus');},0);
    return deferred.promise();
  }

function resolveConfirmation(accepted) {
    if(!activeConfirmation)return;
    var deferred=activeConfirmation,focus=activeConfirmationFocus;
    activeConfirmation=null;activeConfirmationFocus=null;
    $('#unm-confirm-modal').removeClass('open unm-confirm-danger unm-confirm-warning').attr('aria-hidden','true');
    deferred.resolve(accepted===true);
    if(focus&&document.contains(focus))setTimeout(function(){try{focus.focus();}catch(e){}},0);
  }
  
function err(xhr) {
    var m='Request failed';
    try {
      m=JSON.parse(xhr.responseText).error||m;
      
    }
    catch(e) {
      if(xhr.statusText)m+=': '+xhr.statusText;
      
    }
    flash(m,true);
    
  }
  
function loadPeers(options) {
    options=options||{};
    return post('peers').done(function(r) {
      peers=r.peers||[];
      renderPeersSelect();
      renderPeerCards();
      renderDiscoveryResults();
      if(!options.skipHealth)pollPeerHealth();
    });
  }
  
function healthLabel(h) {
    if(!h)return 'Checking…';
    var map={'healthy':'Healthy','warning':'Warning','unhealthy':'Unhealthy','unreachable':'Unreachable','pairing-broken':'Pairing broken','agent-unavailable':'Agent unavailable'};
    return map[h.status]||h.status||'Unknown';
  }

function healthClass(h) { return 'unm-health-'+((h&&h.status)||'checking'); }

function renderSelectedPeerHealth() {
    var id=$('#unm-peer-select').val(),h=peerHealth[id],e=$('#unm-selected-peer-health');
    if(!e.length)return;
    e.attr('class','unm-health-pill '+healthClass(h)).text(healthLabel(h));
    var issues=(h&&h.issues)||[];
    e.attr('title',issues.map(function(i){return i.message;}).join('\n'));
  }

function renderPeersSelect() {
    var s=$('#unm-peer-select'),selected=s.val();
    if(!s.length)return;
    s.empty();
    if(!peers.length)s.append('<option value="">No paired peers</option>');
    peers.forEach(function(p) {
      var h=peerHealth[p.id];
      s.append($('<option>').val(p.id).text(p.name+' ('+p.host+') — '+healthLabel(h)));
    });
    if(selected&&peers.some(function(p){return p.id===selected;}))s.val(selected);
    renderSelectedPeerHealth();
  }

function pollPeerHealth() {
    if(document.hidden||!peers.length)return;
    peers.forEach(function(p){
      post('peerHealth',{peer_id:p.id}).done(function(r){peerHealth[p.id]=r.health||{};renderPeersSelect();renderPeerCards();}).fail(function(xhr){peerHealth[p.id]={status:'unreachable',issues:[{message:'Health request failed'}]};renderPeersSelect();renderPeerCards();});
    });
  }

function renderPeerCards() {
    var c=$('#unm-peer-list');
    if(!c.length)return;
    c.empty();
    if(!peers.length){c.html('<p class="unm-muted">No peers paired yet.</p>');return;}
    peers.forEach(function(p) {
      var caps=p.lastCapabilities||{},storage=caps.storage||{},h=peerHealth[p.id],issues=(h&&h.issues)||[];
      var issueHtml=issues.length?'<ul class="unm-health-issues">'+issues.slice(0,6).map(function(i){return '<li>'+esc(i.message||i.code)+'</li>';}).join('')+'</ul>':'';
      c.append('<div class="unm-card unm-peer"><div><strong>'+esc(p.name)+'</strong> <span class="unm-health-pill '+healthClass(h)+'">'+esc(healthLabel(h))+'</span><br><span class="unm-muted">root@'+esc(p.host)+':'+esc(p.port)+'</span><br><small>Zvol: '+esc(storage.zvolDataset||'image fallback')+' · Images: '+esc(storage.imageDirectory||'unknown')+'</small>'+issueHtml+'</div><div class="unm-actions"><input type="button" value="Test" class="unm-test-peer" data-id="'+esc(p.id)+'"><input type="button" value="Break pairing" class="unm-remove-peer" data-id="'+esc(p.id)+'"></div></div>');
    });
  }

function storageText(vm) {
    var z=0,f=0;
    (vm.disks||[]).forEach(function(d) {
      if(d.type==='zvol')z++;
      else if(d.type==='file')f++;
      
    }
    );
    var x=[];
    if(z)x.push(z+' zvol'+(z===1?'':'s'));
    if(f)x.push(f+' image'+(f===1?'':'s'));
    var readiness=vm.storageReadiness||''; if(readiness)x.push(readiness==='green'?'ZFS native':(readiness==='amber'?'rsync fallback':'blocked')); return x.join(', ')||'Unsupported';
    
  }
  
function storageDetailHtml(vm) {
    var reasons=vm.storageReasons||[];
    if(!reasons.length)return '';
    var cls=vm.storageReadiness==='red'?'unm-bad':'unm-muted';
    return '<small class="unm-storage-detail '+cls+'">'+reasons.slice(0,3).map(function(r){return '<span>'+esc(r)+'</span>';}).join('')+'</small>';
  }

function warmBlockedReason(vm) {
    var reasons=vm.storageBlockingReasons||vm.storageReasons||[];
    return reasons.length?reasons.join(' '):'The VM storage layout is not eligible for Warm Move.';
  }

function featureBadge(label,tooltip,extraClass) {
    var cls='unm-badge unm-feature'+(extraClass?' '+extraClass:'');
    var attrs=' class="'+esc(cls)+'" tabindex="0"';
    if(tooltip)attrs+=' title="'+esc(tooltip)+'" data-tooltip="'+esc(tooltip)+'" aria-label="'+esc(label+': '+tooltip)+'"';
    return '<span'+attrs+'>'+esc(label)+'</span>';
  }

function flags(vm) {
    var x=[];
    if(vm.tpm)x.push(featureBadge('TPM','This VM uses a virtual TPM. Its state will be migrated during final cutover.'));
    if(vm.nvram)x.push(featureBadge('UEFI','This VM uses UEFI firmware. Its NVRAM state will be migrated.'));
    if((vm.isos||[]).length)x.push(featureBadge(vm.isos.length+' ISO'+(vm.isos.length===1?'':'s'),'Attached ISOs may not exist on the destination. They can be copied, retained by path, or detached during migration.'));
    if((vm.usb||[]).length)x.push(featureBadge(vm.usb.length+' USB','USB passthrough needs an explicit retain, remove, or cancel choice. Retention requires a matching device on the destination.','unm-warn'));
    if((vm.pci||[]).length)x.push(featureBadge('PCI blocked','PCIe passthrough is not currently supported and blocks migration.','unm-bad'));
    if(vm.hasCpuPinning)x.push(featureBadge('CPU Pinning','CPU pinning can be retained only when it is valid on the destination; otherwise it must be removed.'));
    if((vm.guestAgent||{}).connected)x.push(featureBadge('Guest agent','QEMU Guest Agent is connected. unMotion can request graceful shutdown and briefly quiesce filesystems for Warm Move snapshots.','unm-ok'));
    if(vm.storageReadiness==='amber')x.push(featureBadge('Warm Move: rsync','Warm Move will use an online seed plus a final powered-off rsync pass. This is less efficient than native ZFS replication.','unm-warn'));
    if(vm.storageReadiness==='green')x.push(featureBadge('Warm Move: ZFS','Native ZFS snapshots and incremental send/receive are available, reducing cutover downtime.','unm-ok'));
    return '<div class="unm-feature-list">'+x.join('')+'</div>';
    
  }
  
function seedFor(vmUuid,peerId) {
    return seeds.find(function(x){return x.vmUuid===vmUuid&&x.peerId===peerId&&x.state!=='REMOVED'&&x.state!=='CONSUMED';})||null;
  }

function replicationFor(vmUuid,peerId) {
    return replications.find(function(x){
      return (x.vmUuid===vmUuid||x.vmId===vmUuid)&&(!peerId||x.peerId===peerId||x.destinationPeerId===peerId);
    })||null;
  }

function recoveryPolicyForVm(vmUuid) {
    return replications.find(function(policy){return String(policy.vmUuid||policy.vmId||'')===String(vmUuid)&&recoveryArmed(policy);})||null;
  }

function renderInventory() {
    var body=$('#unm-vm-body');if(!body.length)return;body.empty();
    var peerId=$('#unm-peer-select').val();
    latestVms.forEach(function(vm) {
      if(vm.error){body.append('<tr><td>'+esc(vm.name)+'</td><td colspan="7" class="unm-bad">'+esc(vm.error)+'</td></tr>');return;}
      var blocked=(vm.pci||[]).length>0,poweredOff=String(vm.state||'').toLowerCase()==='shut off',seed=seedFor(vm.uuid,peerId),actions='<div class="unm-action-stack">';
      if(blocked)actions+='<input type="button" value="PCIe unsupported" disabled title="PCIe passthrough is unsupported">';
      else {
        actions+='<input type="button" class="unm-migrate" data-vm-id="'+esc(vm.uuid)+'" data-vm-name="'+esc(vm.name)+'" value="'+(poweredOff?'Cold migrate':'Power off before cold move')+'" '+(poweredOff?'':'disabled')+'>';
        if(vm.warmMoveEligible&&peerId){
          if(seed){
            if(seed.state==='READY'){
              actions+='<input type="button" class="unm-warm-update" data-vm-id="'+esc(vm.uuid)+'" data-seed-id="'+esc(seed.id)+'" value="Update seed">';
              actions+='<input type="button" class="unm-warm-cutover" data-vm-id="'+esc(vm.uuid)+'" data-vm-name="'+esc(vm.name)+'" data-seed-id="'+esc(seed.id)+'" value="Cut over">';
            } else actions+='<input type="button" value="'+esc(seed.message||seed.state)+'" disabled>';
          } else actions+='<input type="button" class="unm-warm-prepare" data-vm-id="'+esc(vm.uuid)+'" value="Prepare Warm Move">';
        } else if(vm.storageReadiness==='red') {
          actions+='<input type="button" value="Warm Move blocked" disabled title="'+esc(warmBlockedReason(vm))+'">';
        }
      }
      var replication=replicationFor(vm.uuid,peerId);
      if(peerId)actions+='<input type="button" class="unm-replicate" data-vm-id="'+esc(vm.uuid)+'" data-vm-name="'+esc(vm.name)+'" data-replication-id="'+esc(replication?replication.id:'')+'" value="'+(replication?'Configure replication':'Replicate')+'">';
      else actions+='<input type="button" value="Replicate" disabled title="Select a paired destination first">';
      if((vm.pci||[]).length)actions+='<input type="button" value="Clone blocked by PCIe" disabled title="Remove PCIe passthrough before cloning">';
      else if(vm.tpm)actions+='<input type="button" value="Clone blocked by TPM" disabled title="RC2 does not clone virtual TPM identity or secrets">';
      else actions+='<input type="button" class="unm-clone" data-vm-id="'+esc(vm.uuid)+'" data-vm-name="'+esc(vm.name)+'" value="'+(poweredOff?'Clone locally':'Power off before clone')+'" '+(poweredOff?'':'disabled')+'>';
      actions+='</div>';
      var managedRecovery=recoveryPolicyForVm(vm.uuid),autostartHtml=managedRecovery?'<span class="unm-badge unm-recovery-warning">Managed by unMotion</span><br><small class="unm-muted">Native autostart disabled; startup is fenced</small>':esc(vm.autostart);
      body.append('<tr><td><strong>'+esc(vm.name)+'</strong><br><small class="unm-muted">'+esc(vm.uuid)+'</small></td><td>'+esc(vm.state)+'</td><td class="unm-storage-cell">'+esc(storageText(vm))+storageDetailHtml(vm)+'</td><td class="unm-features-cell">'+flags(vm)+'</td><td>'+esc(vm.vcpus)+'</td><td>'+esc(fmtRamMib(vm.memoryMiB))+'</td><td>'+autostartHtml+'</td><td>'+actions+'</td></tr>');
    });
    if(!latestVms.length)body.html('<tr><td colspan="8">No VMs found.</td></tr>');
  }

function renderSeeds() {
    var body=$('#unm-seed-body');if(!body.length)return;body.empty();
    seeds.forEach(function(seed){
      var actions='<input type="button" value="Log" class="unm-seed-log" data-id="'+esc(seed.id)+'"> ';
      if(seed.state==='READY')actions+='<input type="button" value="Update" class="unm-warm-update" data-vm-id="'+esc(seed.vmUuid)+'" data-seed-id="'+esc(seed.id)+'"> <input type="button" value="Cut over" class="unm-warm-cutover" data-vm-id="'+esc(seed.vmUuid)+'" data-vm-name="'+esc(seed.vmName)+'" data-seed-id="'+esc(seed.id)+'"> <input type="button" value="Remove" class="unm-remove-seed" data-id="'+esc(seed.id)+'">';
      else if(seed.state==='INTERRUPTED')actions+='<input type="button" value="Resume" class="unm-resume-seed" data-id="'+esc(seed.id)+'"> <input type="button" value="Remove failed seed" class="unm-remove-seed" data-id="'+esc(seed.id)+'">';
      else if(seed.state==='FAILED')actions+='<input type="button" value="Remove failed seed" class="unm-remove-seed" data-id="'+esc(seed.id)+'">';
      body.append('<tr><td>'+esc(seed.vmName)+'</td><td>'+esc(seed.peerName||seed.peerId)+'</td><td>'+esc(seed.state)+'</td><td>'+esc(seed.lastSyncAt||'—')+'</td><td>'+esc(seed.estimateIncomplete?'At least '+fmtBytes(seed.estimatedChangedBytes):fmtBytes(seed.estimatedChangedBytes))+'</td><td>'+actions+'</td></tr>');
    });
    if(!seeds.length)body.html('<tr><td colspan="6">No prepared moves.</td></tr>');
  }

function loadInventory() {
    var body=$('#unm-vm-body');if(!body.length)return;body.html('<tr><td colspan="8">Loading…</td></tr>');
    post('inventory').done(function(r){latestVms=r.vms||[];seeds=r.seeds||[];renderInventory();renderSeeds();}).fail(err);
  }

function loadSeedsOnly() {
    if(!$('#unm-seed-body').length)return;
    post('seeds').done(function(r){seeds=r.seeds||[];renderSeeds();renderInventory();});
  }

function replicationNativeEligible(vm) {
    if(typeof vm.replicationEligible!=='undefined')return !!vm.replicationEligible;
    return !!vm.nativeZfsWarmMove;
  }

function replicationBlockHtml(vm) {
    var reasons=vm.replicationBlockingReasons||vm.storageBlockingReasons||vm.storageReasons||[];
    return '<div class="unm-callout unm-callout-danger"><strong>Scheduled replication is blocked for this VM.</strong><p>Every writable disk must be a zvol or an image contained in a dedicated, unencrypted ZFS dataset. Shared datasets and rsync/file-copy fallbacks are not supported.</p>'+
      (reasons.length?'<ul>'+reasons.map(function(reason){return '<li>'+esc(reason)+'</li>';}).join('')+'</ul>':'')+
      '<p>unMotion does not convert datasets. Use the <strong>ZFS Master</strong> plugin to create or reorganise dedicated VM datasets, then return and recheck.</p><a class="unm-button-link" href="/Apps">Open Apps to find ZFS Master</a></div>';
  }

function updateReplicationSliders() {
    var rpoSlider=$('#unm-rep-rpo'),retention=$('#unm-rep-retention');
    if(!rpoSlider.length||!retention.length)return;
    var notch=replicationRpoNotches[Number(rpoSlider.val())]||replicationRpoNotches[3];
    var max=replicationRetentionMax(notch.seconds),count=Math.max(1,Math.min(max,Number(retention.val())||1));
    retention.attr('max',max).val(count);
    var retentionTicks='';for(var i=1;i<=max;i++)retentionTicks+='<option value="'+i+'"></option>';
    $('#unm-rep-retention-notches').html(retentionTicks);
    $('#unm-rep-rpo-value').text(notch.label);
    $('#unm-rep-retention-value').text(count+' of '+max+' maximum');
    var spacing=24/count;
    $('#unm-rep-retention-help').text('Keeps the newest verified point in each UTC retention bucket, approximately '+(spacing>=10?spacing.toFixed(0):spacing.toFixed(1)).replace(/\.0$/,'')+' hours apart. The most recent point is always kept.');
  }

function replicationPreflight() {
    var panel=$('#unm-rep-preflight'),peer=$('#unm-rep-peer').val();
    var serial=++activeReplicationPreflightSerial;
    activeReplicationPreflight=null;
    $('#unm-save-replication').prop('disabled',true);
    if(!activeReplicationVm||!replicationNativeEligible(activeReplicationVm)){
      panel.html(replicationBlockHtml(activeReplicationVm||{}));return;
    }
    if(!peer){panel.html('<div class="unm-callout unm-callout-danger">Select a paired destination.</div>');return;}
    var notch=replicationRpoNotches[Number($('#unm-rep-rpo').val())]||replicationRpoNotches[3];
    panel.html('<p class="unm-muted"><span class="unm-spinner unm-spinner-small"></span>Checking the VM, destination, protocol, and dedicated ZFS storage…</p>');
    post('replicationPreflight',{
      vm_id:activeReplicationVm.uuid,
      peer_id:peer,
      rpo_seconds:notch.seconds,
      retention_count:Number($('#unm-rep-retention').val())||1,
      tpm_initial_mode:$('input[name="unm-rep-tpm-mode"]:checked').val()||'none'
    }).done(function(r){
      if(serial!==activeReplicationPreflightSerial)return;
      var pre=r.preflight||r||{},errors=pre.errors||[],warnings=pre.warnings||[];
      activeReplicationPreflight=pre;
      var html='';
      if(errors.length)html+='<div class="unm-callout unm-callout-danger"><strong>Replication cannot be configured.</strong><ul>'+errors.map(function(e){return '<li>'+esc(e.message||e)+'</li>';}).join('')+'</ul></div>';
      if(warnings.length)html+='<div class="unm-callout unm-callout-warning"><strong>Review before saving.</strong><ul>'+warnings.map(function(w){return '<li>'+esc(w.message||w)+'</li>';}).join('')+'</ul></div>';
      if(pre.ready)html+='<div class="unm-callout unm-callout-ok"><strong>Ready for scheduled replication.</strong> The destination will store read-only recovery inventory only.</div>';
      panel.html(html||'<div class="unm-callout unm-callout-danger">The preflight did not return an eligibility result.</div>');
      $('#unm-save-replication').prop('disabled',!pre.ready);
    }).fail(function(xhr){
      if(serial!==activeReplicationPreflightSerial)return;
      var message='Replication preflight failed.';
      try{message=JSON.parse(xhr.responseText).error||message;}catch(ignore){}
      panel.html('<div class="unm-callout unm-callout-danger">'+esc(message)+'</div>');
    });
  }

function showReplicationModal(vmId,vmName,replicationId) {
    var vm=latestVms.find(function(item){return item.uuid===vmId;})||null;
    var policy=replicationId?replications.find(function(item){return item.id===replicationId;}):null;
    activeReplicationId=replicationId||(policy&&policy.id)||'';
    activeReplicationVm=vm;
    activeReplicationPreflight=null;
    if(!vm){flash('The selected VM is no longer available.',true);return;}
    var selectedPeer=(policy&&(policy.peerId||policy.destinationPeerId))||$('#unm-peer-select').val()||'';
    var peerOptions=peers.map(function(peer){return '<option value="'+esc(peer.id)+'" '+(peer.id===selectedPeer?'selected':'')+'>'+esc(peer.name+' ('+peer.host+')')+'</option>';}).join('');
    var rpoSeconds=Number((policy&&(policy.rpoSeconds||policy.rpo_seconds))||3600),rpoIdx=rpoIndex(rpoSeconds);
    var max=replicationRetentionMax(rpoSeconds),retention=Math.max(1,Math.min(max,Number((policy&&(policy.retentionCount||policy.retention_count))||1)));
    var tpmMode=(policy&&(policy.tpmInitialMode||policy.tpm_initial_mode))||'power-cycle';
    var rpoTicks=replicationRpoNotches.map(function(notch,index){return '<option value="'+index+'" label="'+esc(notch.label)+'"></option>';}).join('');
    var tickLabels=replicationRpoNotches.map(function(notch){return '<span>'+esc(notch.label.replace(' hours','h').replace(' hour','h').replace(' min','m'))+'</span>';}).join('');
    var qga=(vm.guestAgent||{}).connected;
    var qgaHtml=qga
      ? '<div class="unm-callout unm-callout-ok"><strong>QEMU Guest Agent connected.</strong> Replication can quiesce filesystems and mark points as guest-agent verified.</div>'
      : '<div class="unm-callout unm-callout-warning"><strong>Replication only:</strong> QEMU Guest Agent is not currently responding. Storage can be replicated, but points created without guest-agent verification are not eligible for a later recovery workflow.</div>';
    var tpmHtml=vm.tpm
      ? '<div class="unm-callout unm-callout-warning"><strong>Virtual TPM detected.</strong> Recovery is more reliable without a TPM. unMotion retains the last safe powered-off TPM copy and labels best-effort captures.</div><div class="unm-choice-list"><label><input type="radio" name="unm-rep-tpm-mode" value="power-cycle" '+(tpmMode==='power-cycle'?'checked':'')+'><strong>Power cycle (recommended)</strong><br><small>Briefly stop and restart the VM to establish an initial safe TPM and firmware checkpoint.</small></label><label><input type="radio" name="unm-rep-tpm-mode" value="best-effort" '+(tpmMode==='best-effort'?'checked':'')+'><strong>Best-effort stun</strong><br><small>Pause the VM while copying TPM files. The checkpoint is labelled best effort and may not be bootable with every disk point.</small></label></div>'
      : '<input type="hidden" name="unm-rep-tpm-mode" value="none"><p class="unm-muted">No virtual TPM was detected; TPM checkpoint selection is not required.</p>';
    var html='<div class="unm-callout unm-callout-info"><strong>Beta2 recovery boundary:</strong> Scheduled replication always leaves replica storage read-only and undefined. A separate, explicitly armed coordinated-recovery workflow may create activation-owned ZFS clones and define a stopped VM; automatic failover remains disabled.</div>'+
      '<div class="unm-form-row"><label for="unm-rep-peer">Destination</label><div><select id="unm-rep-peer" '+(activeReplicationId?'disabled':'')+'><option value="">Select a paired host</option>'+peerOptions+'</select>'+(activeReplicationId?'<small class="unm-muted">Remove and recreate the policy to use a different destination.</small>':'')+'</div></div>'+
      '<div class="unm-form-row"><label for="unm-rep-rpo">Recovery point objective</label><div class="unm-slider-field"><input type="range" id="unm-rep-rpo" min="0" max="9" step="1" value="'+rpoIdx+'" list="unm-rep-rpo-notches"><datalist id="unm-rep-rpo-notches">'+rpoTicks+'</datalist><div class="unm-slider-value" id="unm-rep-rpo-value"></div><div class="unm-slider-notches">'+tickLabels+'</div></div></div>'+
      '<div class="unm-form-row"><label for="unm-rep-retention">Recovery points retained in 24 hours</label><div class="unm-slider-field"><input type="range" id="unm-rep-retention" min="1" max="'+max+'" step="1" value="'+retention+'" list="unm-rep-retention-notches"><datalist id="unm-rep-retention-notches"></datalist><div class="unm-slider-value" id="unm-rep-retention-value"></div><small id="unm-rep-retention-help" class="unm-muted"></small></div></div>'+
      '<h3>Guest consistency and TPM</h3>'+qgaHtml+tpmHtml+'<div id="unm-rep-preflight"></div>';
    $('#unm-replication-modal-title').text((activeReplicationId?'Configure replication for ':'Replicate ')+vmName);
    $('#unm-replication-modal-content').html(html);
    $('#unm-replication-modal').addClass('open');
    updateReplicationSliders();
    replicationPreflight();
  }

function saveReplication() {
    if(!activeReplicationPreflight||!activeReplicationPreflight.ready)return;
    var notch=replicationRpoNotches[Number($('#unm-rep-rpo').val())]||replicationRpoNotches[3];
    var stop=setButtonBusy($('#unm-save-replication'),'Saving');
    post(activeReplicationId?'updateReplication':'createReplication',{
      replication_id:activeReplicationId,
      vm_id:activeReplicationVm.uuid,
      peer_id:$('#unm-rep-peer').val(),
      rpo_seconds:notch.seconds,
      retention_count:Number($('#unm-rep-retention').val())||1,
      tpm_initial_mode:$('input[name="unm-rep-tpm-mode"]:checked').val()||'none'
    }).done(function(){
      $('#unm-replication-modal').removeClass('open');
      flash(activeReplicationId?'Replication policy updated.':'Scheduled replication configured. The initial copy will run when scheduled by unMotion.');
      loadReplications();loadIncomingReplicas();loadInventory();
    }).fail(err).always(stop);
  }

function replicationPointCount(item) {
    if(Array.isArray(item.recoveryPoints))return item.recoveryPoints.length;
    return Number(item.recoveryPointCount||item.pointCount||item.availablePoints||0);
  }

function recoveryRecord(item) {
    if(item&&item.recovery&&typeof item.recovery==='object'&&!Array.isArray(item.recovery))return item.recovery;
    if(item&&typeof item==='object'&&!Array.isArray(item)&&(
      Object.prototype.hasOwnProperty.call(item,'armed')||
      Object.prototype.hasOwnProperty.call(item,'managedAutostart')||
      Object.prototype.hasOwnProperty.call(item,'recoveryProtocolVersion')
    ))return item;
    return {};
  }

function recoveryState(item) {
    item=item||{};
    var recovery=recoveryRecord(item);
    if(recovery&&recovery.state)return String(recovery.state).toUpperCase();
    if(item.recoveryState)return String(item.recoveryState).toUpperCase();
    return 'REPLICATION_ONLY';
  }

function recoveryArmed(item) {
    var recovery=recoveryRecord(item);
    if(!Object.prototype.hasOwnProperty.call(recovery,'armed'))return false;
    return recovery.armed===true||recovery.armed===1||recovery.armed==='1';
  }

function recoveryStateHtml(item) {
    var state=recoveryState(item),klass='unm-badge';
    if(state==='STANDBY'||state==='RECOVERED_RUNNING')klass+=' unm-recovery-ok';
    else if(state.indexOf('FENC')>=0||state.indexOf('FAILED')>=0||state.indexOf('SPLIT_BRAIN')>=0)klass+=' unm-recovery-danger';
    else if(state==='REPLICATION_ONLY'||state==='UNARMED')klass+=' unm-recovery-muted';
    else klass+=' unm-recovery-warning';
    return '<span class="'+klass+'">'+esc(state.replace(/_/g,' '))+'</span>';
  }

function renderReplications() {
    var body=$('#unm-replication-body');if(!body.length)return;body.empty();
    replications.forEach(function(policy){
      var enabled=!(policy.enabled===false||policy.enabled===0||policy.enabled==='0');
      var state=policy.state||policy.status||(enabled?'IDLE':'DISABLED');
      var active=['STARTING','LOCKING','PREFLIGHT','PROBING_GUEST','QUIESCING','SNAPSHOTTING','THAWING','CAPTURING_HOST_STATE','TRANSFERRING','VERIFYING','PUBLISHING','COMMITTING','PRUNING','CLEANING'].indexOf(String(state).toUpperCase())>=0;
      var pointCount=replicationPointCount(policy),retention=Number(policy.retentionCount||policy.retention_count||1);
      var retentionText=pointCount>0?pointCount+' available / retain '+retention:'Retain '+retention+' / 24h';
      var lastSuccess=policy.lastSuccessAt||policy.lastSuccessEpoch||policy.lastSuccessful||policy.lastSyncAt;
      var lag=typeof policy.lagSeconds!=='undefined'?policy.lagSeconds:policy.replicationLagSeconds;
      if(typeof lag==='undefined'){
        var lastEpoch=epochSeconds(lastSuccess);lag=lastEpoch===null?null:Math.max(0,Math.floor(Date.now()/1000)-lastEpoch-Number(policy.rpoSeconds||policy.rpo_seconds||0));
      }
      var activeDisabled=active?' disabled title="Wait for the current replication run to finish"':'';
      var canRemoveUnused=Number(policy.generation||0)===0&&!policy.baseSnapshot&&!policy.pending&&!recoveryArmed(policy);
      var removeAction=canRemoveUnused?'<input type="button" value="Remove unused policy" class="unm-remove-replication" data-id="'+esc(policy.id)+'"'+activeDisabled+'>':'<input type="button" value="Replica removal deferred" disabled title="Disable the policy to pause future runs while preserving destination data, recovery points, and resumable receive state">';
      var actions='<div class="unm-action-stack"><input type="button" value="Run now" class="unm-run-replication" data-id="'+esc(policy.id)+'" '+(active||!enabled?'disabled':'')+'><input type="button" value="'+(enabled?'Disable':'Enable')+'" class="unm-toggle-replication" data-id="'+esc(policy.id)+'" data-enable="'+(enabled?'0':'1')+'"><input type="button" value="Configure" class="unm-replicate" data-vm-id="'+esc(policy.vmUuid||policy.vmId)+'" data-vm-name="'+esc(policy.vmName||policy.vm||'VM')+'" data-replication-id="'+esc(policy.id)+'"'+activeDisabled+'><input type="button" value="Recovery" class="unm-recovery-open" data-id="'+esc(policy.id)+'" data-perspective="source"><input type="button" value="Log" class="unm-replication-log" data-id="'+esc(policy.id)+'">'+removeAction+'</div>';
      body.append('<tr><td>'+esc(policy.vmName||policy.vm||policy.vmUuid)+'</td><td>'+esc(policy.peerName||policy.destinationName||policy.peerId)+'</td><td>'+esc(rpoLabel(policy.rpoSeconds||policy.rpo_seconds))+'</td><td>'+esc(retentionText)+'</td><td>'+esc(state)+'<br>'+recoveryStateHtml(policy)+'</td><td>'+esc(fmtWhen(lastSuccess))+'</td><td>'+esc(enabled?fmtWhen(policy.nextDueAt||policy.nextDueEpoch||policy.nextRunAt):'—')+'</td><td>'+esc(fmtDurationSeconds(lag))+'</td><td>'+actions+'</td></tr>');
    });
    if(!replications.length)body.html('<tr><td colspan="9">No scheduled replications. Choose <strong>Replicate</strong> beside an eligible VM to create one.</td></tr>');
  }

function renderIncomingReplicas() {
    var body=$('#unm-incoming-replica-body');if(!body.length)return;body.empty();
    incomingReplicas.forEach(function(replica){
      var points=replica.recoveryPoints||replica.points||[],latest=replica.latestPoint||replica.latest||{},count=Array.isArray(points)?points.length:Number(replica.recoveryPointCount||replica.pointCount||0);
      if(Array.isArray(points)&&points.length&&!Object.keys(latest).length){
        latest=points.find(function(point){return point.id===replica.currentPointId;})||points.slice().sort(function(a,b){return (epochSeconds(b.createdAt||b.capturedAt||b.slotEpoch)||0)-(epochSeconds(a.createdAt||a.capturedAt||a.slotEpoch)||0);})[0]||{};
      }
      var latestAt=latest.capturedAt||latest.capturedAtEpoch||replica.latestCapturedAt||replica.lastSuccessAt;
      var consistency=latest.consistency||replica.consistency||(latest.guestAgentVerified?'guest-agent verified':'not verified');
      var replicationId=replica.replicationId||replica.replication_id||replica.policyId||replica.id;
      var action=replicationId?'<input type="button" value="Recovery" class="unm-recovery-open" data-id="'+esc(replicationId)+'" data-perspective="destination">':'<input type="button" value="Inventory only" disabled>';
      body.append('<tr><td>'+esc(replica.vmName||replica.vm||replica.vmUuid)+'</td><td>'+esc(replica.sourceName||replica.sourceHostName||replica.sourceHostId||'Unknown source')+'</td><td>'+esc(count)+'</td><td>'+esc(fmtWhen(latestAt))+'</td><td>'+esc(consistency||'—')+'</td><td>'+recoveryStateHtml(replica)+'<br><small class="unm-muted">'+esc(replica.activationState||replica.state||replica.status||'Replica remains inert')+'</small></td><td>'+action+'</td></tr>');
    });
    if(!incomingReplicas.length)body.html('<tr><td colspan="7">No incoming replica inventory.</td></tr>');
  }

function loadReplications() {
    var body=$('#unm-replication-body');if(!body.length)return;
    post('replications').done(function(r){replications=r.replications||r.policies||[];renderReplications();renderInventory();}).fail(function(){body.html('<tr><td colspan="9" class="unm-bad">Scheduled replication inventory is unavailable.</td></tr>');});
  }

function loadIncomingReplicas() {
    var body=$('#unm-incoming-replica-body');if(!body.length)return;
    post('incomingReplicas').done(function(r){incomingReplicas=r.incomingReplicas||r.replicas||[];renderIncomingReplicas();}).fail(function(){body.html('<tr><td colspan="7" class="unm-bad">Incoming replica inventory is unavailable.</td></tr>');});
  }

function recoveryChecksHtml(checks) {
    if(!checks)return '';
    if(!Array.isArray(checks))checks=Object.keys(checks).map(function(key){var value=checks[key];return {label:key,ok:value===true||(value&&value.ok),message:value&&value.message};});
    if(!checks.length)return '';
    return '<ul class="unm-recovery-checks">'+checks.filter(function(check){return check&&typeof check==='object';}).map(function(check){var ok=check.ok===true||check.passed===true;return '<li class="'+(ok?'unm-ok':'unm-bad')+'">'+(ok?'PASS: ':'BLOCKED: ')+esc(check.label||check.name||check.code||'Check')+(check.message?' - '+esc(check.message):'')+'</li>';}).join('')+'</ul>';
  }

function recoveryCheckpointLabel(checkpoint,pointEpoch) {
    var quality=String(checkpoint.quality||checkpoint.state||'unknown').toUpperCase();
    var captured=checkpoint.capturedAt||checkpoint.capturedAtEpoch||checkpoint.createdAt;
    var checkpointEpoch=epochSeconds(captured),baseEpoch=epochSeconds(pointEpoch),distance='';
    var seconds=Number(checkpoint.timeDeltaSeconds);
    if(!isFinite(seconds)&&checkpointEpoch!==null&&baseEpoch!==null)seconds=checkpointEpoch-baseEpoch;
    if(isFinite(seconds))distance=' ('+(seconds>=0?'+':'-')+fmtDurationSeconds(Math.abs(seconds))+')';
    return quality+' - '+fmtWhen(captured)+distance+(checkpoint.tpmPresent?' - TPM':'')+(checkpoint.nvramPresent?' - NVRAM':'');
  }

function recoveryWitnessOptions(selected,payload) {
    selected=Array.isArray(selected)?selected.map(String):[];
    payload=payload||{};
    var recovery=recoveryRecord(payload),policy=payload.policy||{},replica=payload.replica||{};
    var excluded=[recovery.sourceHostId,recovery.destinationHostId,policy.peerHostId,policy.peerId,replica.sourceHostId].filter(Boolean).map(String);
    var candidates=peers.filter(function(peer){
      var caps=peer.lastCapabilities||peer.capabilities||{},features=caps.features||{};
      var min=Number(caps.protocolMinVersion||caps.protocolVersion||0),max=Number(caps.protocolMaxVersion||caps.protocolVersion||0);
      var peerId=String(peer.id||''),hostId=String(peer.hostId||peer.host_id||'');
      return peerId&&min<=6&&max>=6&&Number(caps.recoveryProtocolVersion||0)===1&&features.recoveryControlPlane===true&&excluded.indexOf(peerId)<0&&excluded.indexOf(hostId)<0;
    });
    if(!candidates.length)return '<span class="unm-muted">No additional protocol-6 recovery-capable paired hosts are available as witnesses.</span>';
    return candidates.map(function(peer){
      var id=String(peer.id||''),hostId=String(peer.hostId||peer.host_id||'');
      return '<label><input type="checkbox" class="unm-recovery-witness" value="'+esc(id)+'" '+(selected.indexOf(id)>=0||selected.indexOf(hostId)>=0?'checked':'')+'> '+esc(peer.name||peer.hostName||peer.hostname||hostId||id)+'</label>';
    }).join('');
  }

function recoveryVmName(payload) {
    payload=payload||{};
    var recovery=recoveryRecord(payload);
    return String(payload.vmName||(payload.policy&&payload.policy.vmName)||(payload.replica&&payload.replica.vmName)||recovery.vmName||activeRecoveryId);
  }

function recoveryActionAllowed(payload,name) {
    if(recoveryOperationActive(payload))return false;
    var actions=(payload&&payload.actions)||{};
    return actions[name]===true;
  }

function recoveryOperationActive(payload) {
    payload=payload||{};
    var recovery=(payload.recovery&&typeof payload.recovery==='object')?payload.recovery:{};
    var operation=(recovery.operation&&typeof recovery.operation==='object')?recovery.operation:payload.operation;
    var state=String(operation&&operation.state||'').toUpperCase();
    return state==='QUEUED'||state==='RUNNING';
  }

function recoveryArray(value) {
    return Array.isArray(value)?value:[];
  }

function recoveryOperationHtml(operation) {
    if(!operation||typeof operation!=='object')return '';
    var progress=Number(operation.progress),progressHtml='';
    if(isFinite(progress)){
      progress=Math.max(0,Math.min(100,progress));
      progressHtml='<div class="unm-progress unm-recovery-progress"><span style="width:'+progress+'%"></span></div><small>'+esc(progress.toFixed(0))+'%</small>';
    }
    return '<div class="unm-callout unm-callout-info"><strong>'+esc(operation.type||'Recovery operation')+': '+esc(operation.state||'in progress')+'</strong>'+(operation.message?'<p>'+esc(operation.message)+'</p>':'')+progressHtml+'</div>';
  }

function recoveryHoldHtml(hold) {
    if(!hold||typeof hold!=='object'||!Object.keys(hold).length)return '<div class="unm-callout unm-callout-danger">The host reports HOLDOFF without a complete durable hold record. Recovery controls remain locked.</div>';
    var until=hold.holdUntil||hold.until||'',forever=hold.forever===true||String(until).toLowerCase()==='forever';
    var untilText=forever?'until explicitly released':fmtWhen(until),untilEpoch=forever?null:epochSeconds(until),remaining='';
    if(untilEpoch!==null)remaining=untilEpoch>Math.floor(Date.now()/1000)?' ('+fmtDurationSeconds(untilEpoch-Math.floor(Date.now()/1000))+' remaining)':' (expired; awaiting reconciliation)';
    var acknowledged=hold.acknowledged===true?'acknowledged by destination':(hold.acknowledged===false?'not acknowledged - startup remains fenced':'acknowledgement unknown - controls remain conservative');
    return '<div class="unm-callout '+(hold.acknowledged===true?'unm-callout-info':'unm-callout-danger')+'"><strong>Recovery hold:</strong> '+esc(hold.reason||'unknown reason')+'; '+esc(untilText+remaining)+'<div class="unm-recovery-detail-grid"><span>Hold ID</span><code>'+esc(hold.holdId||'unavailable')+'</code><span>Source boot ID</span><code>'+esc(hold.sourceBootId||'unavailable')+'</code><span>Acknowledgement</span><span>'+esc(acknowledged)+'</span>'+(hold.acknowledgedAt?'<span>Acknowledged at</span><span>'+esc(fmtWhen(hold.acknowledgedAt))+'</span>':'')+'</div></div>';
  }

function recoveryClaim(payload) {
    var recovery=recoveryRecord(payload),claim=(recovery.claim&&typeof recovery.claim==='object')?recovery.claim:{};
    return {
      claimId:String(claim.claimId||recovery.claimId||''),
      pointId:String(claim.pointId||claim.recoveryPointId||''),
      checkpointId:String(claim.checkpointId||''),
      term:Number(claim.term),
      expiresAt:claim.expiresAt||recovery.claimExpiresAt||'',
      evidenceReady:claim.evidenceReady===true,
      selectionHash:String(claim.selectionHash||''),
      kind:String(claim.kind||claim.mode||'')
    };
  }

function recoveryCheckpointSelectable(checkpoint) {
    if(!checkpoint||typeof checkpoint!=='object')return false;
    var id=String(checkpoint.checkpointId||checkpoint.id||''),quality=String(checkpoint.quality||'').toLowerCase();
    return !!id&&checkpoint.selectable!==false&&checkpoint.compatible!==false&&(quality==='safe'||quality==='best-effort');
  }

function recoverySelectedPoint(payload,state) {
    var recovery=recoveryRecord(payload),points=recoveryArray(payload.points),claim=recoveryClaim(payload),activation=(recovery.activation&&typeof recovery.activation==='object')?recovery.activation:{};
    var forced='';
    if(state==='RECOVERY_READY'||recoveryActionAllowed(payload,'canResumeGrant'))forced=claim.pointId;
    else if(['ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'].indexOf(state)>=0)forced=String(activation.pointId||recovery.recoveryPointId||'');
    var eligible=points.filter(function(point){return point&&point.eligible===true&&String(point.id||point.pointId||'');});
    var selected=null;
    if(forced)selected=points.find(function(point){return !!point&&String(point.id||point.pointId||'')===forced;})||null;
    if(!selected&&activeRecoveryPointId)selected=eligible.find(function(point){return String(point.id||point.pointId)===activeRecoveryPointId;})||null;
    if(!selected)selected=eligible[0]||null;
    activeRecoveryPointId=selected?String(selected.id||selected.pointId):'';
    return selected;
  }

function recoverySelectCheckpoint(point,state,payload) {
    var recovery=recoveryRecord(payload),claim=recoveryClaim(payload),activation=(recovery.activation&&typeof recovery.activation==='object')?recovery.activation:{};
    var checkpoints=recoveryArray(point&&point.checkpoints).filter(recoveryCheckpointSelectable),recommended=(point&&point.recommendedCheckpoint)||{};
    var recommendedId=String(recommended.checkpointId||recommended.id||''),forced='';
    if(state==='RECOVERY_READY'||recoveryActionAllowed(payload,'canResumeGrant'))forced=claim.checkpointId;
    else if(['ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'].indexOf(state)>=0)forced=String(activation.checkpointId||recovery.checkpointId||'');
    var selected=null;
    if(forced)selected=checkpoints.find(function(checkpoint){return String(checkpoint.checkpointId||checkpoint.id)===forced;})||null;
    if(!selected&&activeRecoveryCheckpointId)selected=checkpoints.find(function(checkpoint){return String(checkpoint.checkpointId||checkpoint.id)===activeRecoveryCheckpointId;})||null;
    if(!selected&&recommendedId)selected=checkpoints.find(function(checkpoint){return String(checkpoint.checkpointId||checkpoint.id)===recommendedId;})||null;
    if(!selected)selected=checkpoints[0]||null;
    activeRecoveryCheckpointId=selected?String(selected.checkpointId||selected.id):'';
    return {checkpoints:checkpoints,recommendedId:recommendedId,selected:selected};
  }

function recoveryClaimIsFreshAndBound(payload,state) {
    var recovery=recoveryRecord(payload),claim=recoveryClaim(payload),expiry=epochSeconds(claim.expiresAt);
    if(state!=='RECOVERY_READY'||!/^claim-[a-f0-9]{24}$/.test(claim.claimId)||expiry===null||expiry<=Math.floor(Date.now()/1000))return false;
    if(claim.evidenceReady!==true||!claim.selectionHash||claim.pointId!==activeRecoveryPointId||claim.checkpointId!==activeRecoveryCheckpointId)return false;
    if(!isFinite(claim.term)||claim.term!==Number(recovery.term))return false;
    var point=recoveryArray(payload.points).find(function(candidate){return candidate&&String(candidate.id||candidate.pointId||'')===claim.pointId;});
    if(!point||point.eligible!==true)return false;
    if(claim.checkpointId&&!recoveryArray(point.checkpoints).some(function(checkpoint){return recoveryCheckpointSelectable(checkpoint)&&String(checkpoint.checkpointId||checkpoint.id||'')===claim.checkpointId;}))return false;
    return true;
  }

function recoveryPointControlsHtml(payload,state) {
    var points=recoveryArray(payload.points),selected=recoverySelectedPoint(payload,state);
    var resumeGrant=recoveryActionAllowed(payload,'canResumeGrant');
    var pointLocked=recoveryOperationActive(payload)||resumeGrant||['RECOVERY_READY','ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'].indexOf(state)>=0;
    var checkpointLocked=recoveryOperationActive(payload)||resumeGrant||['RECOVERY_READY','ACTIVATING','RECOVERED_STOPPED','RECOVERED_RUNNING','RECOVERY_BOOT_FAILED'].indexOf(state)>=0;
    var html='<h3>Recovery point</h3>';
    if(!points.length)return html+'<div class="unm-callout unm-callout-danger">No recovery point inventory is available.</div>';
    html+='<div class="unm-choice-list">'+points.map(function(point){
      if(!point||typeof point!=='object')return '';
      var id=String(point.id||point.pointId||''),eligible=point.eligible===true&&!!id,checked=id===activeRecoveryPointId,reasons=recoveryArray(point.reasons);
      var details=reasons.length?'<ul class="unm-point-reasons">'+reasons.map(function(reason){return '<li>'+esc(reason)+'</li>';}).join('')+'</ul>':'';
      return '<label class="'+(eligible?'':'unm-choice-disabled')+'"><input type="radio" name="unm-recovery-point" value="'+esc(id)+'" '+(checked?'checked':'')+' '+(!eligible||pointLocked?'disabled':'')+'> <strong>'+esc(fmtWhen(point.capturedAt||point.capturedAtEpoch||point.createdAt))+'</strong> - '+esc(point.consistency||point.quality||'unclassified')+(eligible?'':' - not eligible')+details+'</label>';
    }).join('')+'</div>';
    if(!selected)return html+'<div class="unm-callout unm-callout-danger">No eligible recovery point is selectable. Review the reasons above; recovery remains blocked.</div>';
    var choice=recoverySelectCheckpoint(selected,state,payload),pointTime=selected.capturedAt||selected.capturedAtEpoch||selected.createdAt;
    if(choice.checkpoints.length){
      html+='<h3>TPM / NVRAM checkpoint</h3><div class="unm-choice-list">'+choice.checkpoints.map(function(checkpoint){
        var id=String(checkpoint.checkpointId||checkpoint.id),quality=String(checkpoint.quality||'').toLowerCase(),checked=id===activeRecoveryCheckpointId;
        var risk=quality==='best-effort'?'<div class="unm-recovery-risk">Best effort: guest state may not exactly match this disk point.</div>':'';
        return '<label><input type="radio" name="unm-recovery-checkpoint" value="'+esc(id)+'" '+(checked?'checked':'')+' '+(checkpointLocked?'disabled':'')+'> '+(id===choice.recommendedId?'<strong>Recommended:</strong> ':'')+esc(recoveryCheckpointLabel(checkpoint,pointTime))+risk+'</label>';
      }).join('')+'</div>';
    }else{
      activeRecoveryCheckpointId='';
      html+='<div class="unm-callout unm-callout-info"><strong>No TPM/NVRAM checkpoint is required.</strong> The selected point does not declare coupled host state.</div>';
    }
    return html;
  }

function recoveryEvidenceModes(payload) {
    var recovery=recoveryRecord(payload),arm=payload.arm||{},configured=arm.evidenceModes||payload.evidenceModes||recovery.evidenceModes;
    var allowed=['coordinated','two-host','witness'];
    if(!Array.isArray(configured))return [];
    return configured.map(String).filter(function(mode,index,modes){return allowed.indexOf(mode)>=0&&modes.indexOf(mode)===index;});
  }

function recoveryEvidenceControlsHtml(payload,state,vmName) {
    var resumeGrant=recoveryActionAllowed(payload,'canResumeGrant');
    var modes=recoveryEvidenceModes(payload),canCollect=recoveryActionAllowed(payload,'canGatherEvidence')&&recoveryArmed(payload)&&(state==='STANDBY'||resumeGrant)&&!!activeRecoveryPointId;
    if(!canCollect)return '';
    var labels={coordinated:'Coordinated with reachable source','two-host':'Two-host evidence (split-brain risk)','witness':'Witness quorum'};
    var options=modes.map(function(mode){return '<option value="'+esc(mode)+'">'+esc(labels[mode])+'</option>';}).join('');
    if(!options)return '<div class="unm-callout unm-callout-danger">No evidence method is explicitly enabled for this recovery group.</div>';
    if(modes.length===1&&modes[0]==='coordinated')return '<h3>Authority evidence</h3><input type="hidden" id="unm-recovery-evidence-mode" value="coordinated"><input type="hidden" id="unm-recovery-dns-probe" value="one.one.one.one"><input type="hidden" id="unm-recovery-external-probe" value=""><div class="unm-callout '+(resumeGrant?'unm-callout-danger':'unm-callout-info')+'"><strong>'+(resumeGrant?'Grant acknowledgement is incomplete.':'Coordinated recovery only.')+'</strong> '+(resumeGrant?'Resume the exact durable transaction; the point, checkpoint, activation ID and selection hash cannot change.':'The source must be reachable, the source VM must be verifiably shut off, and native autostart must remain disabled. The source will durably fence itself and grant the exact point/checkpoint selection before the destination can activate it.')+'</div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-evidence" value="'+(resumeGrant?'Resume exact coordinated grant':'Request coordinated recovery grant')+'"></div>';
    var confirmation='RECOVER '+vmName,evidence=payload.evidence||{};
    return '<h3>Authority evidence</h3><div class="unm-form-row"><label for="unm-recovery-evidence-mode">Claim method</label><select id="unm-recovery-evidence-mode">'+options+'</select></div><div class="unm-form-row"><label for="unm-recovery-dns-probe">DNS probe name</label><input id="unm-recovery-dns-probe" type="text" maxlength="253" value="'+esc(evidence.dnsProbe||'one.one.one.one')+'"></div><div class="unm-form-row"><label for="unm-recovery-external-probe">Optional external IP</label><input id="unm-recovery-external-probe" type="text" maxlength="45" value="'+esc(evidence.externalProbe||'')+'" placeholder="For example 1.1.1.1"></div><div id="unm-recovery-confirm-row" class="unm-form-row"><label for="unm-recovery-confirmation">Type <code>'+esc(confirmation)+'</code></label><input id="unm-recovery-confirmation" type="text" autocomplete="off" data-required="'+esc(confirmation)+'"></div><div class="unm-callout unm-callout-warning unm-two-host-warning">Two-host evidence cannot prove that an isolated source is powered off. Guest silence may also mean ICMP is blocked. The destination will require repeated source, guest, gateway, and DNS checks before issuing a short-lived claim.</div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-evidence" value="Collect and verify evidence"></div>';
  }

function recoverySourceControlsHtml(payload,state) {
    var recovery=recoveryRecord(payload),arm=payload.arm||{},armed=recoveryArmed(payload),html='<h3>Source recovery controls</h3>';
    if(!armed&&state==='REPLICATION_ONLY'){
      var ready=arm.ready===true&&recoveryActionAllowed(payload,'canArm');
      html+='<div class="unm-callout unm-callout-danger"><strong>unMotion will take control of VM startup.</strong> Native Unraid autostart will be disabled and continuously reconciled. The VM remains off after a source reboot until destination authority is reconciled.</div>';
      html+=recoveryChecksHtml((arm.reasons||[]).map(function(reason){return {ok:false,label:'Arming prerequisite',message:reason};}));
      if(recoveryArray(arm.warnings).length)html+='<div class="unm-callout unm-callout-warning"><ul>'+arm.warnings.map(function(warning){return '<li>'+esc(warning)+'</li>';}).join('')+'</ul></div>';
      if(arm.ready!==true)html+='<div class="unm-callout unm-callout-danger">Arming readiness was not positively established. Recovery remains replication-only.</div>';
      var desiredAutostart=arm.suggestedDesiredAutostart===true||arm.nativeAutostart===true||recovery.desiredAutostart===true;
      html+='<label class="unm-toggle"><input type="checkbox" id="unm-recovery-desired-autostart" '+(desiredAutostart?'checked':'')+'><span class="unm-toggle-slider"></span><span class="unm-toggle-label">Start this VM only after unMotion completes startup fencing checks</span></label>';
      if(arm.witnessSupported===true)html+='<div class="unm-form-row"><label>Optional recovery witnesses</label><div class="unm-choice-list unm-witness-list">'+recoveryWitnessOptions(recovery.witnessPeerIds||recovery.witnesses||[],payload)+'</div></div>';
      else html+='<p class="unm-muted">Witness quorum is not advertised by this beta2 control plane. No witness can be selected implicitly.</p>';
      html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-arm" value="Arm recovery" '+(ready?'':'disabled')+'></div>';
      return html;
    }
    if(!armed)return html+'<div class="unm-callout unm-callout-danger">The recovery state is transitional or fenced, but no durable armed flag is present. No source action is offered.</div>';
    html+='<p>Native autostart is <strong>disabled</strong>. Desired managed autostart: <strong>'+(recovery.desiredAutostart?'on':'off')+'</strong>.</p>';
    if(recoveryActionAllowed(payload,'canResumeArm')){
      html+='<div class="unm-callout unm-callout-danger"><strong>Arming acknowledgement is incomplete.</strong> Recovery remains fenced. Resume sends the original transaction and managed-autostart intent; it does not create a new authority term.</div><label class="unm-toggle"><input type="checkbox" id="unm-recovery-desired-autostart" '+(recovery.desiredAutostart?'checked':'')+' disabled><span class="unm-toggle-slider"></span><span class="unm-toggle-label">Original managed-autostart intent</span></label><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-arm" value="Resume exact arming transaction"></div>';
      return html;
    }
    if(recoveryActionAllowed(payload,'canResumeDisarm')){
      html+='<div class="unm-callout unm-callout-danger"><strong>Disarm acknowledgement is incomplete.</strong> Native autostart stays disabled until the exact two-phase transaction is reconciled.</div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-disarm" value="Resume exact disarm transaction"></div>';
      return html;
    }
    if(state==='HOLDOFF'){
      html+=recoveryHoldHtml(recovery.hold);
      if(recoveryActionAllowed(payload,'canResumeHold')&&recovery.hold&&recovery.hold.holdId)html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-hold-retry" value="Retry exact hold acknowledgement"></div>';
      else if(recoveryActionAllowed(payload,'canReleaseHold')&&recovery.hold&&recovery.hold.holdId&&recovery.hold.acknowledged===true)html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-hold-release" value="Release acknowledged hold"></div>';
      return html;
    }
    if(state==='STANDBY'&&recoveryActionAllowed(payload,'canHold')){
      html+='<div class="unm-form-row"><label for="unm-recovery-hold-seconds">Graceful shutdown hold</label><select id="unm-recovery-hold-seconds"><option value="900">15 minutes</option><option value="3600" selected>1 hour</option><option value="7200">2 hours</option><option value="14400">4 hours</option><option value="86400">24 hours</option><option value="forever">Until explicitly released</option></select></div><div class="unm-form-row"><label for="unm-recovery-hold-reason">Reason</label><select id="unm-recovery-hold-reason"><option value="vm-poweroff">VM power off</option><option value="vm-restart">VM restart</option><option value="host-shutdown">Host shutdown</option><option value="host-reboot">Host reboot</option></select></div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-hold" value="Set and acknowledge recovery hold"></div>';
    }
    if(state==='STANDBY'&&recoveryActionAllowed(payload,'canDisarm'))html+='<div class="unm-danger-zone"><h3>Disarm recovery</h3><p>Native autostart is restored only after both hosts agree that no activation, hold, failback, or unresolved authority exists.</p><input type="button" class="unm-recovery-disarm" value="Disarm recovery"></div>';
    if(state!=='STANDBY')html+='<div class="unm-callout unm-callout-warning">Source controls are locked while the policy is '+esc(state.replace(/_/g,' ').toLowerCase())+'.</div>';
    return html;
  }

function recoveryDestinationControlsHtml(payload,state) {
    var recovery=recoveryRecord(payload),vmName=recoveryVmName(payload),claim=recoveryClaim(payload),activation=(recovery.activation&&typeof recovery.activation==='object')?recovery.activation:{},html='';
    html+=recoveryPointControlsHtml(payload,state);
    if(state==='HOLDOFF')html+=recoveryHoldHtml(recovery.hold);
    html+=recoveryEvidenceControlsHtml(payload,state,vmName);
    if(state==='RECOVERY_READY'){
      var fresh=recoveryClaimIsFreshAndBound(payload,state);
      html+='<div class="unm-callout '+(fresh?'unm-callout-ok':'unm-callout-danger')+'"><strong>'+(fresh?'Recovery claim ready.':'Recovery claim is incomplete, stale, expired, or does not match the displayed selection.')+'</strong><div class="unm-recovery-detail-grid"><span>Claim ID</span><code>'+esc(claim.claimId||'unavailable')+'</code><span>Expires</span><span>'+esc(fmtWhen(claim.expiresAt))+'</span><span>Bound point</span><code>'+esc(claim.pointId||'unavailable')+'</code><span>Bound checkpoint</span><code>'+esc(claim.checkpointId||'none required')+'</code></div></div>';
      if(fresh&&recoveryActionAllowed(payload,'canActivate'))html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-activate" value="Activate claimed replica"></div>';
      else if(recoveryActionAllowed(payload,'canRenewClaim'))html+='<div class="unm-callout unm-callout-warning">The exact claim is near expiry or expired. Renewal keeps the same authority term, activation ID, point, checkpoint and selection hash, and rechecks source fencing plus guest-address silence.</div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-renew-claim" value="Renew exact coordinated claim"></div>';
    }
    if(activation&&Object.keys(activation).length)html+='<div class="unm-callout unm-callout-info"><strong>Activation '+esc(activation.activationId||'')+'</strong><div class="unm-recovery-detail-grid"><span>VM state</span><span>'+esc(activation.vmState||activation.state||'unknown')+'</span><span>Point</span><code>'+esc(activation.pointId||'unavailable')+'</code><span>Checkpoint</span><code>'+esc(activation.checkpointId||'none')+'</code>'+(activation.startedAt?'<span>Started</span><span>'+esc(fmtWhen(activation.startedAt))+'</span>':'')+(activation.healthAt?'<span>Guest healthy</span><span>'+esc(fmtWhen(activation.healthAt))+'</span>':'')+'</div></div>';
    if(state==='RECOVERY_BOOT_FAILED'||state==='RECOVERED_STOPPED'){
      if(state==='RECOVERY_BOOT_FAILED')html+='<div class="unm-callout unm-callout-danger"><strong>Boot health failed.</strong> Beta2 can crash-safely reinstall the exact checkpoint authorized by the coordinated claim. Selecting a different TPM/NVRAM backup requires a new reviewed control transaction and is deferred.</div>';
      if(state==='RECOVERED_STOPPED'&&recoveryActionAllowed(payload,'canStartActivation'))html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-start-activation" value="Start recovered VM"></div>';
      if(recoveryActionAllowed(payload,'canRetryCheckpoint')&&activeRecoveryCheckpointId)html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-retry-checkpoint" value="'+(state==='RECOVERY_BOOT_FAILED'?'Reinstall authorized checkpoint and retry':'Start with authorized checkpoint')+'"></div>';
    }
    if(state==='RECOVERED_RUNNING'){
      html+='<div class="unm-callout unm-callout-warning">Stop the recovered VM before checkpoint retry, activation removal, or cold-failback preflight.</div>';
      if(recoveryActionAllowed(payload,'canStopActivation'))html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-stop-activation" value="Stop recovered VM"></div>';
    }
    if(state==='RECOVERED_STOPPED'||state==='RECOVERY_BOOT_FAILED'){
      html+='<div class="unm-danger-zone"><h3>Stopped recovered activation</h3><div class="unm-callout unm-callout-info"><strong>Cold failback is preflight-only in beta2.</strong> This check does not transfer storage, start the source VM, or change authority.</div>';
      if(recoveryActionAllowed(payload,'canFailbackPreflight'))html+='<div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-failback" value="Check cold failback prerequisites"></div>';
      if(recoveryActionAllowed(payload,'canRemoveActivation')){
        var phrase='REMOVE '+vmName;
        html+='<div class="unm-form-row"><label for="unm-recovery-remove-confirmation">Type <code>'+esc(phrase)+'</code></label><input id="unm-recovery-remove-confirmation" type="text" autocomplete="off" data-required="'+esc(phrase)+'"></div><div class="unm-modal-inline-actions"><input type="button" class="unm-recovery-remove-activation" value="Remove stopped activation"></div>';
      }
      html+='</div>';
    }
    if(state.indexOf('SPLIT_BRAIN')===0||state==='FENCED')html+='<div class="unm-callout unm-callout-danger"><strong>Fail closed.</strong> Split-brain or authority uncertainty locks activation, replication advancement, pruning, and failback. Beta2 offers no implicit survivor selection.</div>';
    return html;
  }

function updateRecoveryEvidenceConfirmation() {
    var twoHost=$('#unm-recovery-evidence-mode').val()==='two-host';
    $('#unm-recovery-confirm-row,.unm-two-host-warning').toggle(twoHost);
  }

function renderRecoveryModal(payload) {
    payload=payload||{};activeRecoveryPayload=payload;
    var recovery=recoveryRecord(payload),state=recoveryState(payload),role=String(payload.direction||''),html='';
    var vmName=recoveryVmName(payload);
    $('#unm-recovery-modal-title').text('Recovery control - '+vmName);
    if(role!=='source'&&role!=='destination'){
      $('#unm-recovery-modal-content').html('<div class="unm-callout unm-callout-danger"><strong>Recovery status was rejected.</strong> The server did not provide an authoritative source/destination direction.</div>');return;
    }
    if(activeRecoveryPerspective&&role!==activeRecoveryPerspective){
      $('#unm-recovery-modal-content').html('<div class="unm-callout unm-callout-danger"><strong>Recovery status identity mismatch.</strong> The selected '+esc(activeRecoveryPerspective)+' inventory returned a '+esc(role)+' record. No action is available.</div>');return;
    }
    html+='<div class="unm-recovery-summary"><div><span class="unm-muted">State</span><br>'+recoveryStateHtml(recovery)+'</div><div><span class="unm-muted">Authority term</span><br>'+esc(recovery.term||0)+'</div><div><span class="unm-muted">Authority</span><br>'+esc(recovery.authority||'UNKNOWN')+'</div><div><span class="unm-muted">Role</span><br>'+esc(role)+'</div></div>';
    html+='<div class="unm-callout unm-callout-warning"><strong>Coordinated manual recovery only in beta2.</strong> No timer starts replicas automatically, and an unreachable source cannot grant recovery authority.</div>';
    if(recovery.lastError)html+='<div class="unm-callout unm-callout-danger">'+esc(recovery.lastError)+'</div>';
    html+=recoveryOperationHtml(recovery.operation||payload.operation);
    if(recoveryOperationActive(payload))html+='<div class="unm-callout unm-callout-info">Recovery mutation controls are locked until the current durable operation completes or fails.</div>';
    html+=role==='source'?recoverySourceControlsHtml(payload,state):recoveryDestinationControlsHtml(payload,state);
    $('#unm-recovery-modal-content').html(html);
    updateRecoveryEvidenceConfirmation();
  }

function loadRecoveryStatus(openModal) {
    if(!activeRecoveryId)return;
    var requestedId=activeRecoveryId,requestedPerspective=activeRecoveryPerspective,serial=++activeRecoveryStatusSerial;
    if(activeRecoveryStatusRequest&&activeRecoveryStatusRequest.readyState!==4)activeRecoveryStatusRequest.abort();
    if(openModal){$('#unm-recovery-modal').addClass('open').attr('aria-hidden','false');$('#unm-recovery-modal-content').html('<div class="unm-page-loading"><span class="unm-spinner"></span><span>Loading recovery status...</span></div>');}
    activeRecoveryStatusRequest=post('recoveryStatus',{replication_id:requestedId});
    activeRecoveryStatusRequest.done(function(payload){
      if(serial!==activeRecoveryStatusSerial||requestedId!==activeRecoveryId||requestedPerspective!==activeRecoveryPerspective)return;
      renderRecoveryModal(payload);
    }).fail(function(xhr,status){
      if(status==='abort'||serial!==activeRecoveryStatusSerial||requestedId!==activeRecoveryId)return;
      $('#unm-recovery-modal-content').html('<div class="unm-callout unm-callout-danger">'+esc((xhr.responseJSON&&xhr.responseJSON.error)||'Recovery status is unavailable.')+'</div>');
    }).always(function(){if(serial===activeRecoveryStatusSerial)activeRecoveryStatusRequest=null;});
  }

function recoveryMutation(action,data,button,label) {
    var requestedId=activeRecoveryId,requestedDirection=String((activeRecoveryPayload&&activeRecoveryPayload.direction)||'');
    if(!requestedId||['source','destination'].indexOf(requestedDirection)<0){flash('Recovery identity is incomplete; no mutation was sent.',true);return;}
    data=data||{};data.replication_id=requestedId;data.direction=requestedDirection;
    var stop=setButtonBusy(button,label||'Working');
    post(action,data).done(function(result){
      flash((result.operation&&result.operation.message)||result.message||'Recovery state updated.');
      if(activeRecoveryId===requestedId)loadRecoveryStatus(false);
      loadReplications();loadIncomingReplicas();loadInventory();
    }).fail(err).always(stop);
  }

function startWarmOperation(vmId,action,button) {
    var peer=$('#unm-peer-select').val();if(!peer){flash('Select a paired destination.',true);return;}
    var vm=latestVms.find(function(v){return v.uuid===vmId;})||{};
    var stopChecking=setButtonBusy($(button),'Checking');
    function launch(warnings){
      warnings=warnings||[];
      function begin(){
        stopChecking();
        var stopBusy=setButtonBusy($(button),action==='prepare'?'Preparing':'Updating');
        post(action==='prepare'?'prepareWarm':'updateWarm',{vm_id:vmId,peer_id:peer}).done(function(){flash((action==='prepare'?'Warm Move preparation':'Prepared copy update')+' started.');loadInventory();}).fail(err).always(stopBusy);
      }
      function confirmTpm(){
        if(action!=='prepare'||!vm.tpm){begin();return;}
        requestConfirmation({title:'Confirm TPM-aware preparation',message:'This VM uses a virtual TPM. The prepared copy contains storage only and cannot be booted until a final powered-off cutover transfers current TPM and UEFI state.',acceptLabel:'Prepare storage',tone:'warning'}).done(function(accepted){if(accepted)begin();else stopChecking();});
      }
      if(!warnings.length){confirmTpm();return;}
      requestConfirmation({title:'Destination warnings',message:warnings.map(function(w,i){return (i+1)+'. '+w;}).join('\n'),acceptLabel:'Continue',tone:'warning'}).done(function(accepted){if(accepted)confirmTpm();else stopChecking();});
    }
    if(action==='prepare'){
      post('preflight',{vm_id:vmId,peer_id:peer,options:JSON.stringify({migration_mode:'warm-prepare',iso_action:'remove',source_cleanup_action:'unregister'})}).done(function(r){
        var pre=r.preflight||{};
        if(!pre.ready){flash((pre.errors||['Warm Move preparation is blocked.']).join('; '),true);stopChecking();return;}
        launch(pre.warnings||[]);
      }).fail(function(xhr){err(xhr);stopChecking();});
    }else{
      post('peerHealth',{peer_id:peer}).done(function(r){
        var h=r.health||{},messages=(h.issues||[]).map(function(i){return i.message||i.code;});
        if(h.migrationBlocked){flash('Destination health blocks this update: '+messages.join('; '),true);stopChecking();return;}
        launch(messages);
      }).fail(function(xhr){err(xhr);stopChecking();});
    }
  }

function selectedResourceOptions() {
    var opts= {
      
    }
    ;
    if($('#unm-vcpu-count').length)opts.vcpu_count=parseInt($('#unm-vcpu-count').val(),10);
    if($('#unm-memory-gib').length)opts.memory_mib=Math.round(parseFloat($('#unm-memory-gib').val())*1024);
    if($('#unm-pinning-action').length)opts.cpu_pinning_action=$('#unm-pinning-action').val();
    if($('input[name="unm-source-cleanup"]:checked').length)opts.source_cleanup_action=$('input[name="unm-source-cleanup"]:checked').val();
    if($('#unm-conflict-action').length)opts.destination_conflict_action=$('#unm-conflict-action').val();
    if(activeWarmSeedId){opts.warm_seed_id=activeWarmSeedId;opts.migration_mode='warm-cutover';}
    return opts;
    
  }
  
function showPreflight(vmId,vmName,isoOverride,resourceOverride,triggerButton) {
    var peer=$('#unm-peer-select').val();
    if(!peer) {
      flash('Pair a destination server first.',true);
      return;
      
    }
    activeOperation='migration';activeVmId=vmId;
    activeVmName=vmName;
    activeWarmSeedId=(resourceOverride||{}).warm_seed_id||'';
    if(triggerButton)activeMigrateButton=$(triggerButton);
    var busyTarget=triggerButton?$(triggerButton):$('#unm-recheck');
    var stopBusy=setButtonBusy(busyTarget,'Checking');
    var iso=isoOverride||$('#unm-default-iso').val()||(appSettings.copy_isos_default?'copy':'remove');
    var preservedUsb=(resourceOverride|| {
      
    }
    )._usb_action||'cancel';
    var preservedSourceCleanup=(resourceOverride||{}).source_cleanup_action||appSettings.source_cleanup_default||'unregister';
    var preservedConflict=(resourceOverride||{}).destination_conflict_action||'cancel';
    var requestOpts=$.extend( {
      iso_action:iso,source_cleanup_action:preservedSourceCleanup,destination_conflict_action:preservedConflict
    }
    ,resourceOverride|| {
      
    }
    );
    delete requestOpts._usb_action;
    post('preflight', {
      vm_id:vmId,peer_id:peer,options:JSON.stringify(requestOpts)
    }
    ).done(function(r) {
      var p=r.preflight,v=p.vm|| {
        
      }
      ,rp=p.resourcePlan|| {
        
      }
      ,srcRes=rp.source|| {
        
      }
      ,req=rp.requested|| {
        
      }
      ,dest=rp.destination|| {
        
      }
      ,pin=rp.pinning|| {
        
      }
      ,cap=p.capacity|| {
        
      }
      ;
      activePreflight=p;
      $('#unm-modal-title').text((activeWarmSeedId?'Warm Move cutover ':'Migrate ')+vmName+' to '+((p.peer|| {
        
      }
      ).name||'peer'));
      var lines=[];
      (p.plan||[]).forEach(function(i) {
        lines.push(String(i.kind).toUpperCase()+': '+i.source+' → '+i.destination+(i.action?' ('+i.action+')':'')+(i.bytes?' ['+fmtBytes(i.bytes)+']':''));
        
      }
      );
      var html='';
      if((p.errors||[]).length)html+='<div class="unm-bad"><strong>Blocked</strong><ul>'+p.errors.map(function(x) {
        return '<li>'+esc(x)+'</li>';
        
      }
      ).join('')+'</ul></div>';
      if((p.warnings||[]).length)html+='<div class="unm-warn"><strong>Warnings</strong><ul>'+p.warnings.map(function(x) {
        return '<li>'+esc(x)+'</li>';
        
      }
      ).join('')+'</ul></div>';
      if(activeWarmSeedId)html+='<div class="unm-card unm-ok"><strong>Prepared copy selected</strong><p>The VM may remain running until cutover begins. unMotion will shut it down gracefully, send the final delta, transfer TPM/UEFI state, and start it on the destination.</p></div>';
      html+='<div class="unm-grid unm-resource-grid"><div class="unm-card"><strong>Destination resources</strong><p>Online CPUs: '+esc(dest.cpuOnlineCount||'unknown')+' ('+esc(dest.cpuOnlineSet||'unknown')+')</p><p>RAM available: '+esc(fmtBytes(dest.memoryAvailableBytes))+' of '+esc(fmtBytes(dest.memoryTotalBytes))+'</p></div>';
      html+='<div class="unm-card"><strong>Destination capacity</strong><p>Zvol: '+esc(fmtBytes((cap.available|| {
        
      }
      ).zvol))+' free / '+esc(fmtBytes((cap.required|| {
        
      }
      ).zvol))+' required</p><p>Images: '+esc(fmtBytes((cap.available|| {
        
      }
      ).image))+' free / '+esc(fmtBytes((cap.required|| {
        
      }
      ).image))+' required</p><p>ZFS image datasets: '+esc(fmtBytes((cap.required||{}).dataset))+' required</p><p>ISOs: '+esc(fmtBytes((cap.available|| {
        
      }
      ).iso))+' free / '+esc(fmtBytes((cap.required|| {
        
      }
      ).iso))+' required</p></div></div>';
      html+='<div class="unm-card"><strong>Destination VM resources</strong><p class="unm-muted">Source: '+esc(srcRes.vcpus||v.vcpus||1)+' vCPU, '+esc(fmtRamMib(srcRes.memoryMiB||v.memoryMiB||0))+' RAM</p><div class="unm-form-row"><label>vCPUs</label><input id="unm-vcpu-count" type="number" min="1" max="'+esc(dest.cpuOnlineCount||4096)+'" value="'+esc(req.vcpus||v.vcpus||1)+'"></div>';
      html+='<div class="unm-form-row"><label>RAM (GiB)</label><input id="unm-memory-gib" type="number" min="0.125" step="0.125" max="'+esc(Math.max(0.125,Number(dest.memoryAvailableBytes||0)/1073741824).toFixed(3))+'" value="'+esc((Number(req.memoryMiB||v.memoryMiB||128)/1024).toFixed(3).replace(/0+$/,'').replace(/\.$/,''))+'"></div>';
      if(pin.present) {
        html+='<div class="unm-form-row"><label>CPU pinning</label><select id="unm-pinning-action"><option value="retain" '+(pin.effectiveAction==='retain'?'selected':'')+' '+(!pin.valid?'disabled':'')+'>Retain current pinning</option><option value="remove" '+(pin.effectiveAction!=='retain'?'selected':'')+'>Remove CPU/NUMA pinning</option></select></div>';
        html+='<p class="unm-muted">'+(pin.valid?'Current pinning references are valid on the destination.':'Current pinning is not valid on the destination and will be removed.')+'</p>';
        
      }
      else html+='<input type="hidden" id="unm-pinning-action" value="none"><p class="unm-muted">No source CPU or NUMA pinning is configured.</p>';
      html+='</div><div class="unm-pre">'+esc(lines.join('\n'))+'</div>';
      if((v.usb||[]).length) {
        html+='<div class="unm-card"><strong>USB devices currently attached</strong><ul>';
        v.usb.forEach(function(u) {
          var id=(u.vendor||'?')+':'+(u.product||'?');
          var desc=u.description?' — '+esc(u.description):'';
          var origin=u.liveOnly?' <span class="unm-badge unm-warn">runtime only</span>':'';
          var match=u.foundOnDestination?'<span class="unm-ok">matching VID:PID found</span>':'<span class="unm-warn">not found on destination</span>';
          html+='<li>'+esc(id)+desc+origin+' — '+match+(u.destinationDescription?' — '+esc(u.destinationDescription):'')+'</li>';
          
        }
        );
        html+='</ul></div><div class="unm-form-row"><label>USB passthrough</label><select id="unm-usb-action"><option value="cancel" '+(preservedUsb==='cancel'?'selected':'')+'>Cancel migration</option><option value="retain" '+(preservedUsb==='retain'?'selected':'')+'>Proceed and retain portable USB configuration</option><option value="remove" '+(preservedUsb==='remove'?'selected':'')+'>Proceed and remove USB devices</option></select></div>';
        
      }
      else html+='<input type="hidden" id="unm-usb-action" value="retain">';
      var conflictItems=((p.conflicts||{}).items||[]);
      if(conflictItems.length){
        html+='<div class="unm-card unm-warn"><strong>Existing destination copy detected</strong><ul>'+conflictItems.map(function(c){return '<li>'+esc(String(c.kind||'item').toUpperCase()+': '+(c.path||'unknown')+(c.state?' ('+c.state+')':''))+'</li>';}).join('')+'</ul><div class="unm-form-row"><label>Destination conflict</label><select id="unm-conflict-action"><option value="cancel" '+(preservedConflict==='cancel'?'selected':'')+'>Cancel and leave destination untouched</option><option value="overwrite" '+(preservedConflict==='overwrite'?'selected':'')+'>Overwrite retained destination VM and disk data</option></select></div><p class="unm-muted">Overwrite is only allowed for a stopped VM with the same UUID or storage carrying a matching unMotion ownership marker.</p></div>';
      }else html+='<input type="hidden" id="unm-conflict-action" value="cancel">';
      html+='<div class="unm-form-row"><label>Optical media</label><select id="unm-iso-action"><option value="copy" '+(iso==='copy'?'selected':'')+'>Copy attached ISOs</option><option value="remove" '+(iso==='remove'?'selected':'')+'>Leave drives empty</option><option value="retain" '+(iso==='retain'?'selected':'')+'>Retain original paths</option></select></div>';
      html+='<div class="unm-card"><strong>Source handling after migration</strong><div class="unm-choice-list"><label><input type="radio" name="unm-source-cleanup" value="unregister" '+(preservedSourceCleanup==='unregister'?'checked':'')+'> <strong>Unregister source VM, retain storage</strong><br><span class="unm-muted">Remove the source libvirt definition while retaining disks, UEFI NVRAM and TPM state.</span></label><label><input type="radio" name="unm-source-cleanup" value="retain" '+(preservedSourceCleanup==='retain'?'checked':'')+'> <strong>Keep source VM registered and rename it</strong><br><span class="unm-muted">Disable autostart and append “ - Migrated to '+esc((p.peer||{}).name||'destination')+'”.</span></label><label><input type="radio" name="unm-source-cleanup" value="delete" '+(preservedSourceCleanup==='delete'?'checked':'')+'> <strong>Delete source VM and storage after validation</strong><br><span class="unm-muted">Delete only after five minutes of continuous destination runtime.</span></label></div></div>';
      $('#unm-modal-content').html(html);
      $('#unm-modal').addClass('open');
      $('#unm-confirm-migrate').val('Migrate');$('#unm-recheck').show();
      updateMigrationEligibility();
      
    }
    ).fail(err).always(stopBusy);
    
  }

function showClonePreflight(vmId,vmName,cloneName,triggerButton) {
    activeOperation='clone';activeVmId=vmId;activeVmName=vmName;activeWarmSeedId='';activeCloneName=cloneName||vmName+' - Clone';
    var stopBusy=setButtonBusy(triggerButton||$('#unm-recheck'),'Checking');
    var customization=$('#unm-clone-customization').val()||'ubuntu-dhcp';
    post('clonePreflight',{vm_id:vmId,clone_name:activeCloneName,options:JSON.stringify({guest_customization:customization})}).done(function(r){
      var p=r.preflight||{},clone=p.clone||{},lines=[];activePreflight=p;
      (p.plan||[]).forEach(function(i){lines.push(String(i.kind||'item').toUpperCase()+': '+(i.source||'')+' â†’ '+(i.destination||'')+(i.bytes?' ['+fmtBytes(i.bytes)+']':''));});
      $('#unm-modal-title').text('Clone '+vmName+' on this server');
      var html='<div class="unm-card"><div class="unm-form-row"><label>Clone name</label><input id="unm-clone-name" type="text" maxlength="128" value="'+esc(activeCloneName)+'"></div>';
      html+='<div class="unm-form-row"><label>Guest handling</label><select id="unm-clone-customization"><option value="ubuntu-dhcp" '+(clone.customization==='ubuntu-dhcp'?'selected':'')+'>Ubuntu via guest agent: reset identity and use DHCP</option><option value="none-disconnected" '+(clone.customization==='none-disconnected'?'selected':'')+'>Do not alter guest; keep every NIC link down</option></select></div></div>';
      if((p.errors||[]).length)html+='<div class="unm-bad"><strong>Blocked</strong><ul>'+p.errors.map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul></div>';
      if((p.warnings||[]).length)html+='<div class="unm-warn"><strong>Safety notes</strong><ul>'+p.warnings.map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul></div>';
      html+='<div class="unm-card"><strong>New KVM identity</strong><p>UUID: '+esc(clone.uuid||'pending')+'</p><p>MACs: '+esc((clone.macs||[]).join(', ')||'none')+'</p><p>Autostart: disabled; final power state: stopped</p></div>';
      html+='<div class="unm-pre">'+esc(lines.join('\n'))+'</div>';
      $('#unm-modal-content').html(html);$('#unm-modal').addClass('open');$('#unm-confirm-migrate').val('Clone');$('#unm-recheck').show();
      updateMigrationEligibility();
    }).fail(err).always(stopBusy);
  }
  
function recheckPreflight() {
    if(activeOperation==='clone'){activeCloneName=$('#unm-clone-name').val()||'';showClonePreflight(activeVmId,activeVmName,activeCloneName,null);return;}
    var opts=selectedResourceOptions();
    opts._usb_action=$('#unm-usb-action').val()||'cancel';
    showPreflight(activeVmId,activeVmName,$('#unm-iso-action').val(),opts,null);
    
  }
  
function updateMigrationEligibility() {
    var disabled=!activePreflight||!activePreflight.ready;
    var reason='';
    if(activeOperation==='clone'){$('#unm-confirm-migrate').prop('disabled',disabled).attr('title',disabled?'Resolve clone preflight errors.':'');return;}
    if(activePreflight&&(activePreflight.vm||{}).usb&&activePreflight.vm.usb.length&&$('#unm-usb-action').val()==='cancel') {disabled=true;reason='Choose how to handle USB passthrough.';}
    var conflicts=((activePreflight||{}).conflicts||{}).items||[];
    if(conflicts.length&&$('#unm-conflict-action').val()==='cancel') {disabled=true;reason='Choose overwrite or close the migration dialog.';}
    $('#unm-confirm-migrate').prop('disabled',disabled).attr('title',reason);
  }

  function confirmRiskyMigration(opts) {
    var warnings=[];
    if(activePreflight&&((activePreflight.vm||{}).usb||[]).length) warnings.push('USB passthrough is attached. Confirm that the selected retain/remove action is intentional.');
    if(opts.source_cleanup_action==='delete') warnings.push('The source VM and its disks will be permanently deleted after the destination has run continuously for five minutes.');
    if(opts.destination_conflict_action==='overwrite') warnings.push('The existing destination VM and/or disk data will be permanently deleted before transfer.');
    if(activeWarmSeedId&&activePreflight&&activePreflight.vm&&activePreflight.vm.tpm)warnings.push('This TPM-enabled VM will only become bootable after the final powered-off TPM and UEFI state transfer. The prepared storage alone is not a recovery copy.');
    var health=(activePreflight&&activePreflight.peerHealth)||{};
    var healthIssues=(health.issues||[]).filter(function(i){return !i.blocksMigration;});
    if(healthIssues.length)warnings.push('The destination reports health warnings: '+healthIssues.map(function(i){return i.message||i.code;}).join('; '));
    if(!warnings.length)return $.Deferred().resolve(true).promise();
    return requestConfirmation({title:'Confirm risky migration',message:warnings.map(function(w,i){return (i+1)+'. '+w;}).join('\n'),acceptLabel:'Start migration',tone:'danger'});
  }

  function startMigration() {
    if(activeOperation==='clone')return startClone();
    var usb=$('#unm-usb-action').val();
    if(usb==='cancel') {
      flash('Choose how to handle USB passthrough or cancel.',true);
      return;
      
    }
    var opts=$.extend(selectedResourceOptions(), {
      usb_action:usb,iso_action:$('#unm-iso-action').val(),warm_seed_id:activeWarmSeedId
    }
    );
    if(!opts.vcpu_count||opts.vcpu_count<1) {
      flash('Enter a valid vCPU count.',true);
      return;
      
    }
    if(!opts.memory_mib||opts.memory_mib<128) {
      flash('Enter at least 0.125 GiB RAM.',true);
      return;
      
    }
    confirmRiskyMigration(opts).done(function(accepted){
      if(!accepted)return;
      var stopBusy=setButtonBusy($('#unm-confirm-migrate'),'Starting');
      post('startMigration', {
        vm_id:activeVmId,peer_id:$('#unm-peer-select').val(),options:JSON.stringify(opts)
      }
      ).done(function(r) {
        $('#unm-modal').removeClass('open');
        flash((activeWarmSeedId?'Warm Move cutover':'Migration')+' job '+r.job.id+' started.');
        activeWarmSeedId='';
        loadJobs();
        pollPeerHealth();

      }
      ).fail(err).always(stopBusy);
    });

  }

  function startClone() {
    var cloneName=$('#unm-clone-name').val()||'',customization=$('#unm-clone-customization').val()||'none-disconnected';
    if(!cloneName){flash('Enter a clone name.',true);return;}
    requestConfirmation({title:'Confirm local clone',message:'Create a full independent local clone named “'+cloneName+'”?\n\nThe source must remain powered off. USB passthrough will be removed and the clone will finish stopped.',acceptLabel:'Create clone',tone:'warning'}).done(function(accepted){
      if(!accepted)return;
      var stopBusy=setButtonBusy($('#unm-confirm-migrate'),'Cloning');
      post('startClone',{vm_id:activeVmId,clone_name:cloneName,options:JSON.stringify({guest_customization:customization})}).done(function(r){
        $('#unm-modal').removeClass('open');flash('Local clone job '+r.job.id+' started.');loadInventory();loadJobs();
      }).fail(err).always(stopBusy);
    });
  }
  
function isCancellable(state) {
    return ['STARTING','PREFLIGHT','PREPARING_DESTINATION','SHUTTING_DOWN','SNAPSHOTTING','CONVERTING','TRANSFERRING','COPYING_STORAGE','HOST_STATE','DEFINING_DESTINATION','DEFINING_CLONE','GUEST_CUSTOMIZING','CANCELLING'].indexOf(state)>=0;
    
  }
  
function loadJobs() {
    var body=$('#unm-job-body');
    if(!body.length)return;
    post('jobs').done(function(r) {
      body.empty();
      (r.jobs||[]).slice(0,20).forEach(function(j) {
        var failed=['FAILED','INTERRUPTED'].indexOf(j.state)>=0,retry=failed&&(j.jobType||'migration')!=='clone';
        var actions='<input type="button" value="Log" class="unm-log" data-id="'+esc(j.id)+'"> ';
        if(retry)actions+='<input type="button" value="Resume" class="unm-resume" data-id="'+esc(j.id)+'"> ';
        if((isCancellable(j.state)||failed)&&j.state!=='CANCELLING')actions+='<input type="button" value="Cancel" class="unm-cancel-job" data-id="'+esc(j.id)+'"> ';
        var cleanupAction=((j.request||{}).SOURCE_CLEANUP_ACTION||'unregister');
        var removable=['CANCELLED','COMPLETE_CLEANED'].indexOf(j.state)>=0||(['COMPLETE','COMPLETE_WITH_WARNINGS'].indexOf(j.state)>=0&&cleanupAction!=='delete');
        if(removable)actions+='<input type="button" value="Remove" class="unm-remove-job" data-id="'+esc(j.id)+'">';
        body.append('<tr><td>'+esc(j.vm)+'</td><td>'+esc(j.peerName||j.peerId)+'</td><td>'+esc(j.state)+'</td><td><div class="unm-progress"><span style="width:'+parseInt(j.progress||0,10)+'%"></span></div><small>'+esc(j.progress||0)+'%</small></td><td>'+esc(j.message||'')+'</td><td>'+actions+'</td></tr>');
        
      }
      );
      if(!(r.jobs||[]).length)body.html('<tr><td colspan="6">No migration or clone jobs yet.</td></tr>');
      
    }
    ).fail(err);
    
  }
  
function refreshLog() {
    if(!currentLogId)return;
    var action=currentLogType==='seed'?'seedLog':(currentLogType==='replication'?'replicationLog':'jobLog');
    var data=currentLogType==='seed'?{seed_id:currentLogId}:(currentLogType==='replication'?{replication_id:currentLogId}:{job_id:currentLogId});
    post(action,data).done(function(r) {
      var e=$('#unm-log-text');
      e.text(r.log||'(empty)');
      e.scrollTop(e[0].scrollHeight);
      
    }
    );
    
  }
  
function showLog(id,type) {
    currentLogId=id;currentLogType=type||'job';
    $('#unm-log-title').text(currentLogType==='seed'?'Prepared-copy log':(currentLogType==='replication'?'Replication log':'Job log'));
    refreshLog();
    $('#unm-log-modal').addClass('open');
    if(logTimer)clearInterval(logTimer);
    logTimer=setInterval(refreshLog,2000);
    
  }
  
function renderSshStatus(ssh) {
    var box=$('#unm-ssh-status');
    if(!box.length)return;
    ssh=ssh|| {
      
    }
    ;
    var url=ssh.managementUrl||'/Settings/ManagementAccess';
    var link='<a class="unm-button-link" href="'+esc(url)+'">Open Management Access</a>';
    if(ssh.enabled&&ssh.running&&ssh.listening) {
      box.empty();
      return;
      
    }
    var reason=!ssh.enabled?'SSH is disabled on this server.':(ssh.running?'The SSH daemon is not listening on the configured port.':'SSH is enabled in Unraid, but the SSH daemon is not running.');
    box.html('<div class="unm-card unm-status-warning"><strong>SSH required</strong><p>'+esc(reason)+' unMotion requires SSH on every participating host for pairing and migration.</p>'+link+'</div>');
    
  }
  
function loadSettings() {
    return post('settings').done(function(r) {
      appSettings=r.settings||appSettings;
      var s=appSettings;
      if($('#unm-settings-form').length) {
        Object.keys(s).forEach(function(k) {
          var e=$('[name="'+k+'"]');
          if(!e.length)return;
          if(e.attr('type')==='checkbox')e.prop('checked',!!s[k]);
          else e.val(String(s[k]));
          
        }
        );
        $('#unm-save-settings').prop('disabled',true);
        
      }
      $('#unm-default-iso').val(s.copy_isos_default?'copy':'remove');
      var c=r.capabilities|| {
        
      }
      ;
      $('#unm-local-id').text(c.hostId||'');
      $('#unm-local-name').text(c.hostname||'');
      renderSshStatus(c.ssh|| {
        
      }
      );
      
    }
    ).fail(err);
    
  }
  
function saveSettings() {
    var p= {
      
    }
    ;
    $('#unm-settings-form').serializeArray().forEach(function(x) {
      p[x.name]=x.value;
      
    }
    );
    $('#unm-settings-form input[type=checkbox][name]').each(function() {
      p[this.name]=$(this).prop('checked');
      
    }
    );
    post('saveSettings', {
      payload:JSON.stringify(p)
    }
    ).done(function(r) {
      appSettings=r.settings||appSettings;
      flash('Settings saved.');
      $('#unm-save-settings').prop('disabled',true);
      if(appSettings.discovery)discover();
      
    }
    ).fail(err);
    
  }
  
function discoveryKey(p) {
    return (p.hostId||'')!==''?'id:'+p.hostId:'addr:'+(p.host||'')+':'+(p.port||22);
  }

function mergeDiscoveredPeers(found) {
    var byKey={};
    discoveredPeers.forEach(function(p){byKey[discoveryKey(p)]=p;});
    (found||[]).forEach(function(p){
      var key=discoveryKey(p);
      if(byKey[key])$.extend(byKey[key],p);
      else { discoveredPeers.push(p); byKey[key]=p; }
    });
  }

function renderDiscoveryResults() {
    var c=$('#unm-discovery-results');
    if(!c.length)return;
    var html='';
    discoveredPeers.forEach(function(p) {
      var already=peers.some(function(existing) {
        return (p.hostId&&existing.hostId===p.hostId)||(existing.host===p.host&&Number(existing.port||22)===Number(p.port||22));
      });
      html+='<div class="unm-card unm-peer"><div><strong>'+esc(p.name)+'</strong><br>'+esc(p.host)+':'+esc(p.port)+'</div>'+(already?'<span class="unm-ok">Paired</span>':'<input type="button" value="Use" class="unm-use-discovery" data-host="'+esc(p.host)+'" data-port="'+esc(p.port)+'">')+'</div>';
    });
    if(discoveryInFlight)html+='<p class="unm-muted unm-discovery-status"><span class="unm-spinner unm-spinner-small"></span> Checking for new peers…</p>';
    else if(!discoveredPeers.length)html='<p class="unm-muted">No plugin peers discovered on this multicast domain.</p>';
    c.html(html);
  }

function discover() {
    if(discoveryInFlight)return;
    var c=$('#unm-discovery-results');
    if(!c.length)return;
    discoveryInFlight=true;
    renderDiscoveryResults();
    post('discover').done(function(r) {
      if(!r.supported) {
        c.html('<p class="unm-warn">'+esc(r.message)+'</p>');
        return;
      }
      mergeDiscoveredPeers(r.peers||[]);
    }).fail(err).always(function(){discoveryInFlight=false;renderDiscoveryResults();});
  }

function pair() {
    var stopBusy=setButtonBusy($('#unm-pair'),'Pairing');
    post('pairPeer', {
      host:$('#peer-host').val(),port:$('#peer-port').val(),password:$('#peer-password').val()
    }
    ).done(function(r) {
      $('#peer-password').val('');
      flash('Paired both ways with '+r.peer.name+'. The password was discarded.');
      loadPeers().then(discover);
      
    }
    ).fail(err).always(stopBusy);
    
  }
  
$(function() {
    var peerRequest=loadPeers();
    loadInventory();
    loadJobs();
    loadReplications();
    loadIncomingReplicas();
    var settingsRequest=loadSettings();
    if($('#unm-settings-loading').length) {
      setSettingsLoading(true,'Loading settings and paired hosts…');
      $.when(peerRequest,settingsRequest).always(function(){
        setSettingsLoading(false);
        if($('#unm-discovery-results').length&&appSettings.discovery) {
          discover();
          discoveryTimer=setInterval(discover,20000);
        }
      });
    } else settingsRequest.done(function() {
      if($('#unm-discovery-results').length&&appSettings.discovery) {
        discover();
        discoveryTimer=setInterval(discover,20000);
      }
    });
    if($('#unm-peer-list').length){
      peerRefreshTimer=setInterval(function(){if(!document.hidden)loadPeers({skipHealth:true});},5000);
    }
    $(document).on('click','.unm-migrate',function() {
      showPreflight($(this).attr('data-vm-id'),$(this).attr('data-vm-name'),null,null,this);
      
    }
    );
    $(document).on('click','.unm-clone',function(){showClonePreflight($(this).attr('data-vm-id'),$(this).attr('data-vm-name'),$(this).attr('data-vm-name')+' - Clone',this);});
    $(document).on('click','.unm-replicate',function(){showReplicationModal($(this).attr('data-vm-id'),$(this).attr('data-vm-name'),$(this).attr('data-replication-id')||'');});
    $(document).on('input change','#unm-rep-rpo,#unm-rep-retention',updateReplicationSliders);
    $(document).on('change','#unm-rep-peer,#unm-rep-rpo,#unm-rep-retention,input[name="unm-rep-tpm-mode"]',replicationPreflight);
    $('#unm-replication-recheck').on('click',replicationPreflight);
    $('#unm-save-replication').on('click',saveReplication);
    $(document).on('click','.unm-run-replication',function(){
      var button=this,stop=setButtonBusy(button,'Starting');
      post('runReplicationNow',{replication_id:$(this).data('id')}).done(function(){flash('Replication run queued.');loadReplications();}).fail(err).always(stop);
    });
    $(document).on('click','.unm-toggle-replication',function(){
      var button=this,enable=String($(this).attr('data-enable'))==='1',stop=setButtonBusy(button,enable?'Enabling':'Disabling');
      post('setReplicationEnabled',{replication_id:$(this).data('id'),enabled:enable?1:0}).done(function(){flash('Scheduled replication '+(enable?'enabled.':'disabled.'));loadReplications();}).fail(err).always(stop);
    });
    $(document).on('click','.unm-remove-replication',function(){
      var button=this,replicationId=$(this).data('id');
      requestConfirmation({title:'Remove unused replication policy?',message:'This generation-0 policy has never produced a recovery point. This removes only the local policy; no destination inventory is deleted.',acceptLabel:'Remove policy',tone:'warning'}).done(function(accepted){
        if(!accepted)return;
        var stop=setButtonBusy(button,'Removing');
        post('removeReplication',{replication_id:replicationId}).done(function(){flash('Unused replication policy removed. No destination inventory was deleted.');loadReplications();loadIncomingReplicas();loadInventory();}).fail(err).always(stop);
      });
    });
    $(document).on('click','.unm-replication-log',function(){showLog($(this).data('id'),'replication');});
    $(document).on('click','.unm-recovery-open',function(){
      activeRecoveryId=String($(this).attr('data-id')||'');
      activeRecoveryPerspective=String($(this).attr('data-perspective')||'');
      activeRecoveryPayload=null;activeRecoveryPointId='';activeRecoveryCheckpointId='';
      loadRecoveryStatus(true);
    });
    $(document).on('click','.unm-recovery-refresh',function(){loadRecoveryStatus(false);});
    $(document).on('change','#unm-recovery-evidence-mode',updateRecoveryEvidenceConfirmation);
    $(document).on('change','input[name="unm-recovery-point"]',function(){
      activeRecoveryPointId=String($(this).val()||'');activeRecoveryCheckpointId='';
      renderRecoveryModal(activeRecoveryPayload||{});
    });
    $(document).on('change','input[name="unm-recovery-checkpoint"]',function(){activeRecoveryCheckpointId=String($(this).val()||'');});
    $(document).on('click','.unm-recovery-arm',function(){
      var button=this,witnesses=[];$('.unm-recovery-witness:checked').each(function(){witnesses.push($(this).val());});
      var desiredAutostart=$('#unm-recovery-desired-autostart').prop('checked')?1:0;
      requestConfirmation({title:'Arm manual recovery?',message:'VM startup responsibility will transfer to unMotion. Native Unraid autostart will be disabled and the VM will remain fenced whenever destination authority cannot be established.',acceptLabel:'Arm recovery',tone:'warning'}).done(function(accepted){
        if(accepted)recoveryMutation('recoveryArm',{desired_autostart:desiredAutostart,witness_peer_ids:JSON.stringify(witnesses)},button,'Arming');
      });
    });
    $(document).on('click','.unm-recovery-disarm',function(){var button=this;requestConfirmation({title:'Disarm recovery?',message:'This is allowed only when both peers prove that no recovered activation, unresolved authority, or hold exists.',acceptLabel:'Disarm recovery',tone:'warning'}).done(function(accepted){if(accepted)recoveryMutation('recoveryDisarm',{},button,'Disarming');});});
    $(document).on('click','.unm-recovery-hold',function(){
      var value=String($('#unm-recovery-hold-seconds').val()||''),reason=String($('#unm-recovery-hold-reason').val()||'');
      var reasons=['vm-poweroff','vm-restart','host-shutdown','host-reboot'];
      if(reasons.indexOf(reason)<0){flash('Select a valid graceful hold reason.',true);return;}
      var holdUntil=value==='forever'?'forever':new Date(Date.now()+Number(value)*1000).toISOString();
      if(value!=='forever'&&(!Number(value)||Number(value)<1)){flash('Select a valid hold duration.',true);return;}
      recoveryMutation('recoveryHold',{reason:reason,hold_until:holdUntil},this,'Setting hold');
    });
    $(document).on('click','.unm-recovery-hold-retry',function(){
      var recovery=recoveryRecord(activeRecoveryPayload||{}),hold=recovery.hold||{},reason=String(hold.reason||''),holdUntil=String(hold.holdUntil||'');
      if(!/^hold-[a-f0-9]{24}$/.test(String(hold.holdId||''))||['vm-poweroff','vm-restart','host-shutdown','host-reboot'].indexOf(reason)<0||!holdUntil){flash('The pending hold transaction is incomplete; retry was blocked.',true);return;}
      var button=this;
      requestConfirmation({title:'Retry hold acknowledgement?',message:'Retry this exact durable hold. No new hold or authority term will be created.',acceptLabel:'Retry hold',tone:'warning'}).done(function(accepted){if(accepted)recoveryMutation('recoveryHold',{reason:reason,hold_until:holdUntil},button,'Retrying hold');});
    });
    $(document).on('click','.unm-recovery-hold-release',function(){
      var recovery=recoveryRecord(activeRecoveryPayload||{}),hold=recovery.hold||{},holdId=String(hold.holdId||'');
      if(!holdId){flash('The durable hold identity is unavailable; release was blocked.',true);return;}
      var button=this;
      requestConfirmation({title:'Release recovery hold?',message:'Release the acknowledged recovery hold on both paired hosts.',acceptLabel:'Release hold',tone:'warning'}).done(function(accepted){if(accepted)recoveryMutation('recoveryHoldRelease',{hold_id:holdId},button,'Releasing');});
    });
    $(document).on('click','.unm-recovery-evidence',function(){
      var mode=String($('#unm-recovery-evidence-mode').val()||''),allowed=recoveryEvidenceModes(activeRecoveryPayload||{});
      var required=String($('#unm-recovery-confirmation').attr('data-required')||''),confirmation=String($('#unm-recovery-confirmation').val()||'');
      if(allowed.indexOf(mode)<0){flash('The selected evidence method is not explicitly enabled for this recovery group.',true);return;}
      if(mode==='two-host'&&confirmation!==required){flash('Type '+required+' exactly before using a two-host evidence claim.',true);return;}
      if(!activeRecoveryPointId){flash('Select an eligible recovery point before collecting evidence.',true);return;}
      recoveryMutation('recoveryEvidence',{point_id:activeRecoveryPointId,checkpoint_id:activeRecoveryCheckpointId,mode:mode,confirmation:mode==='two-host'?confirmation:'',dns_probe:String($('#unm-recovery-dns-probe').val()||''),external_probe:String($('#unm-recovery-external-probe').val()||'')},this,'Checking evidence');
    });
    $(document).on('click','.unm-recovery-activate',function(){
      var payload=activeRecoveryPayload||{},state=recoveryState(payload),claim=recoveryClaim(payload);
      if(!recoveryClaimIsFreshAndBound(payload,state)){flash('The claim is stale, expired, or no longer matches the displayed point and checkpoint.',true);return;}
      var button=this;
      requestConfirmation({title:'Activate claimed replica?',message:'Activate the exact claimed replica for '+recoveryVmName(payload)+'. Evidence, authority, point GUIDs, and host state will be revalidated immediately before start.',acceptLabel:'Activate replica',tone:'danger'}).done(function(accepted){if(accepted)recoveryMutation('recoveryActivate',{claim_id:claim.claimId,start_vm:1},button,'Activating');});
    });
    $(document).on('click','.unm-recovery-renew-claim',function(){
      var payload=activeRecoveryPayload||{},claim=recoveryClaim(payload);
      if(!/^claim-[a-f0-9]{24}$/.test(claim.claimId)||!claim.selectionHash){flash('The exact retained claim is incomplete; renewal was blocked.',true);return;}
      var button=this;
      requestConfirmation({title:'Renew coordinated claim?',message:'The source fence, Guest Agent addresses, point, checkpoint and authority term will be revalidated without changing the selection.',acceptLabel:'Renew claim',tone:'warning'}).done(function(accepted){if(accepted)recoveryMutation('recoveryRenewClaim',{},button,'Renewing claim');});
    });
    $(document).on('click','.unm-recovery-start-activation',function(){var button=this;requestConfirmation({title:'Start recovered VM?',message:'The exact activation storage, source fence, Guest Agent addresses, authority term, and QEMU Guest Agent health will be revalidated.',acceptLabel:'Start recovered VM',tone:'danger'}).done(function(accepted){if(accepted)recoveryMutation('recoveryStartActivation',{},button,'Starting');});});
    $(document).on('click','.unm-recovery-retry-checkpoint',function(){if(!activeRecoveryCheckpointId){flash('The authorized checkpoint is unavailable.',true);return;}var button=this,checkpointId=activeRecoveryCheckpointId;requestConfirmation({title:'Retry authorized checkpoint?',message:'Crash-safely reinstall the exact authorized TPM/NVRAM checkpoint, verify that the recovered VM is stopped, and retry boot.',acceptLabel:'Reinstall and retry',tone:'danger'}).done(function(accepted){if(accepted)recoveryMutation('recoveryRetryCheckpoint',{checkpoint_id:checkpointId,start_vm:1},button,'Retrying');});});
    $(document).on('click','.unm-recovery-stop-activation',function(){var button=this;requestConfirmation({title:'Stop recovered VM?',message:'Gracefully stop the recovered VM. Destination authority and activation storage will be retained.',acceptLabel:'Stop recovered VM',tone:'warning'}).done(function(accepted){if(accepted)recoveryMutation('recoveryStopActivation',{},button,'Stopping');});});
    $(document).on('click','.unm-recovery-remove-activation',function(){
      var input=$('#unm-recovery-remove-confirmation'),required=String(input.attr('data-required')||''),confirmation=String(input.val()||'');
      if(!required||confirmation!==required){flash('Type '+required+' exactly before removing the stopped activation.',true);return;}
      var button=this;
      requestConfirmation({title:'Remove stopped activation?',message:'Remove only the stopped recovered VM definition and activation-owned storage. Retained recovery points stay inert and source authority is not restored automatically.',acceptLabel:'Remove activation',tone:'danger'}).done(function(accepted){if(accepted)recoveryMutation('recoveryRemoveActivation',{confirmation:confirmation},button,'Removing');});
    });
    $(document).on('click','.unm-recovery-failback',function(){var button=this;requestConfirmation({title:'Check cold failback prerequisites?',message:'Beta2 will only run preflight. It will not transfer data, start the source VM, or change recovery authority.',acceptLabel:'Run preflight'}).done(function(accepted){if(accepted)recoveryMutation('recoveryFailback',{action:'preflight'},button,'Checking failback');});});
    $(document).on('click','.unm-warm-prepare',function(){startWarmOperation($(this).attr('data-vm-id'),'prepare',this);});
    $(document).on('click','.unm-warm-update',function(){startWarmOperation($(this).attr('data-vm-id'),'update',this);});
    $(document).on('click','.unm-warm-cutover',function(){
      showPreflight($(this).attr('data-vm-id'),$(this).attr('data-vm-name'),null,{warm_seed_id:$(this).attr('data-seed-id'),migration_mode:'warm-cutover'},this);
    });
    $(document).on('click','.unm-resume-seed',function(){var b=this,stop=setButtonBusy(b,'Resuming');post('resumeWarm',{seed_id:$(this).attr('data-id')}).done(function(){flash('Prepared-copy resume started.');loadSeedsOnly();}).fail(err).always(stop);});
    $(document).on('click','.unm-remove-seed',function(){var button=this,seedId=$(this).attr('data-id');requestConfirmation({title:'Remove prepared copy?',message:'Remove this prepared copy from the destination and release its exact ZFS snapshots.',acceptLabel:'Remove prepared copy',tone:'danger'}).done(function(accepted){if(!accepted)return;var stop=setButtonBusy(button,'Removing');post('removeWarm',{seed_id:seedId}).done(function(){flash('Prepared-copy removal started.');loadSeedsOnly();}).fail(err).always(stop);});});
    $(document).on('click','.unm-seed-log',function(){showLog($(this).data('id'),'seed');});
    $('#unm-confirm-migrate').on('click',startMigration);
    $('#unm-confirm-cancel').on('click',function(){resolveConfirmation(false);});
    $('#unm-confirm-accept').on('click',function(){resolveConfirmation(true);});
    $(document).on('keydown',function(event){
      if(!activeConfirmation)return;
      if(event.key==='Escape'){event.preventDefault();resolveConfirmation(false);}
    });
    $('#unm-peer-select').on('change',function(){renderSelectedPeerHealth();renderInventory();});
    $('#unm-recheck').on('click',recheckPreflight);
    $('.unm-modal-close').on('click',function() {
      var modal=$(this).closest('.unm-modal');
      modal.removeClass('open').attr('aria-hidden','true');
      if(modal.is('#unm-recovery-modal')){
        activeRecoveryStatusSerial++;
        if(activeRecoveryStatusRequest&&activeRecoveryStatusRequest.readyState!==4)activeRecoveryStatusRequest.abort();
        activeRecoveryStatusRequest=null;activeRecoveryId='';activeRecoveryPerspective='';activeRecoveryPayload=null;activeRecoveryPointId='';activeRecoveryCheckpointId='';
      }
      if($(this).closest('#unm-log-modal').length) {
        currentLogId='';currentLogType='job';
        if(logTimer) {
          clearInterval(logTimer);
          logTimer=null;
          
        }
        
      }
      
    }
    );
    $(document).on('click','.unm-log',function() {
      showLog($(this).data('id'));
      
    }
    );
    $(document).on('click','.unm-resume',function() {
      post('resumeJob', {
        job_id:$(this).data('id')
      }
      ).done(function() {
        flash('Resume requested.');
        loadJobs();
        
      }
      ).fail(err);
      
    }
    );
    $(document).on('click','.unm-cancel-job',function() {
      var jobId=$(this).data('id');
      requestConfirmation({title:'Cancel this job?',message:'Clone jobs remove only their newly-created destinations; migration jobs preserve the source where it is safe to do so.',acceptLabel:'Cancel job',tone:'warning'}).done(function(accepted){
        if(!accepted)return;
        post('cancelJob', {job_id:jobId}).done(function() {
          flash('Cancellation requested.');
          loadJobs();
        }).fail(err);
      });
      
    }
    );
    $(document).on('change','#unm-usb-action',updateMigrationEligibility);
    $(document).on('change','#unm-conflict-action',function(){if($(this).val()==='overwrite')recheckPreflight();else updateMigrationEligibility();});
    $(document).on('input','#unm-clone-name',function(){$('#unm-confirm-migrate').prop('disabled',true).attr('title','Recheck after changing the clone name.');});
    $(document).on('change','#unm-clone-customization',function(){activeCloneName=$('#unm-clone-name').val()||activeCloneName;showClonePreflight(activeVmId,activeVmName,activeCloneName,null);});
    $(document).on('click','.unm-remove-job',function() {
      var jobId=$(this).data('id');
      requestConfirmation({title:'Remove job history?',message:'Remove this completed or cancelled job and its local log. VM storage is not deleted.',acceptLabel:'Remove job'}).done(function(accepted){if(accepted)post('removeJob',{job_id:jobId}).done(function(){flash('Job removed from history.');loadJobs();}).fail(err);});
    });
    $('#unm-clear-jobs').on('click',function(){
      var button=this;
      requestConfirmation({title:'Clear eligible job history?',message:'Remove all eligible completed and cancelled jobs and their local logs. VM storage is not deleted.',acceptLabel:'Clear job history',tone:'warning'}).done(function(accepted){if(!accepted)return;var stopBusy=setButtonBusy(button,'Clearing');post('clearJobs').done(function(r){flash((r.removed||0)+' job(s) removed from history.');loadJobs();}).fail(err).always(stopBusy);});
    });
    $('#unm-refresh').on('click',function() {
      loadPeers();
      loadInventory();
      loadJobs();
      loadReplications();
      loadIncomingReplicas();
      
    }
    );
    $('#unm-save-settings').on('click',saveSettings);
    $('#unm-discover').on('click',discover);
    $('#unm-pair').on('click',pair);
    $(document).on('click','.unm-use-discovery',function() {
      $('#peer-host').val($(this).data('host'));
      $('#peer-port').val($(this).data('port'));
      
    }
    );
    $(document).on('click','.unm-test-peer',function() {
      post('testPeer', {
        peer_id:$(this).data('id')
      }
      ).done(function(r) {
        flash('Connected to '+r.peer.name+'.');
        loadPeers();
        
      }
      ).fail(err);
      
    }
    );
    $(document).on('click','.unm-remove-peer',function() {
      var peerId=$(this).data('id');
      requestConfirmation({title:'Break pairing?',message:'Break this pairing on both hosts and remove the dedicated SSH keys.',acceptLabel:'Break pairing',tone:'danger'}).done(function(accepted){
        if(!accepted)return;
        post('breakPairing', {peer_id:peerId}).done(function() {
          flash('Pairing removed from both hosts where reachable.');
          loadPeers().then(discover);
        }).fail(err);
      });
      
    }
    );
    if($('#unm-job-body').length){setInterval(loadJobs,4000);setInterval(loadSeedsOnly,4000);setInterval(loadReplications,5000);setInterval(loadIncomingReplicas,5000);}
    pollPeerHealth();healthTimer=setInterval(pollPeerHealth,Math.max(5,Number(appSettings.health_poll_seconds||10))*1000);
    document.addEventListener('visibilitychange',function(){if(!document.hidden){pollPeerHealth();if($('#unm-peer-list').length)loadPeers({skipHealth:true});}});
    
  }
  );
  

}
)(jQuery);
