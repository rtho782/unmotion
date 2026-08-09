(function($) {
  
'use strict';
  
var api='/plugins/unmotion/api.php';
  
var peers=[],seeds=[],latestVms=[],peerHealth={},discoveredPeers=[];
  
var appSettings= {
    copy_isos_default:true,discovery:true,source_cleanup_default:'unregister',health_poll_seconds:10,debug_logging:false
  }
  ;
  
var activeVmId='',activeVmName='',activePreflight=null,activeWarmSeedId='';
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
      actions+='</div>';
      body.append('<tr><td><strong>'+esc(vm.name)+'</strong><br><small class="unm-muted">'+esc(vm.uuid)+'</small></td><td>'+esc(vm.state)+'</td><td class="unm-storage-cell">'+esc(storageText(vm))+storageDetailHtml(vm)+'</td><td class="unm-features-cell">'+flags(vm)+'</td><td>'+esc(vm.vcpus)+'</td><td>'+esc(fmtRamMib(vm.memoryMiB))+'</td><td>'+esc(vm.autostart)+'</td><td>'+actions+'</td></tr>');
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

function startWarmOperation(vmId,action,button) {
    var peer=$('#unm-peer-select').val();if(!peer){flash('Select a paired destination.',true);return;}
    var vm=latestVms.find(function(v){return v.uuid===vmId;})||{};
    var stopChecking=setButtonBusy($(button),'Checking');
    function launch(warnings){
      warnings=warnings||[];
      if(warnings.length&&!confirm('Destination warnings:\n\n'+warnings.map(function(w,i){return (i+1)+'. '+w;}).join('\n')+'\n\nContinue?')){stopChecking();return;}
      if(action==='prepare'&&vm.tpm&&!confirm('This VM uses a virtual TPM. The prepared copy contains storage only and cannot be booted until a final powered-off cutover transfers current TPM and UEFI state. Continue?')){stopChecking();return;}
      stopChecking();
      var stopBusy=setButtonBusy($(button),action==='prepare'?'Preparing':'Updating');
      post(action==='prepare'?'prepareWarm':'updateWarm',{vm_id:vmId,peer_id:peer}).done(function(){flash((action==='prepare'?'Warm Move preparation':'Prepared copy update')+' started.');loadInventory();}).fail(err).always(stopBusy);
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
    activeVmId=vmId;
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
      updateMigrationEligibility();
      
    }
    ).fail(err).always(stopBusy);
    
  }
  
function recheckPreflight() {
    var opts=selectedResourceOptions();
    opts._usb_action=$('#unm-usb-action').val()||'cancel';
    showPreflight(activeVmId,activeVmName,$('#unm-iso-action').val(),opts,null);
    
  }
  
function updateMigrationEligibility() {
    var disabled=!activePreflight||!activePreflight.ready;
    var reason='';
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
    if(!warnings.length)return true;
    return window.confirm('ARE YOU SURE?\n\n'+warnings.map(function(w,i){return (i+1)+'. '+w;}).join('\n')+'\n\nContinue with migration?');
  }

  function startMigration() {
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
    if(!confirmRiskyMigration(opts))return;
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
    
  }
  
function isCancellable(state) {
    return ['STARTING','PREFLIGHT','PREPARING_DESTINATION','SHUTTING_DOWN','SNAPSHOTTING','CONVERTING','TRANSFERRING','HOST_STATE','DEFINING_DESTINATION','CANCELLING'].indexOf(state)>=0;
    
  }
  
function loadJobs() {
    var body=$('#unm-job-body');
    if(!body.length)return;
    post('jobs').done(function(r) {
      body.empty();
      (r.jobs||[]).slice(0,20).forEach(function(j) {
        var retry=['FAILED','INTERRUPTED'].indexOf(j.state)>=0;
        var actions='<input type="button" value="Log" class="unm-log" data-id="'+esc(j.id)+'"> ';
        if(retry)actions+='<input type="button" value="Resume" class="unm-resume" data-id="'+esc(j.id)+'"> ';
        if((isCancellable(j.state)||retry)&&j.state!=='CANCELLING')actions+='<input type="button" value="Cancel" class="unm-cancel-job" data-id="'+esc(j.id)+'"> ';
        var cleanupAction=((j.request||{}).SOURCE_CLEANUP_ACTION||'unregister');
        var removable=['CANCELLED','COMPLETE_CLEANED'].indexOf(j.state)>=0||(['COMPLETE','COMPLETE_WITH_WARNINGS'].indexOf(j.state)>=0&&cleanupAction!=='delete');
        if(removable)actions+='<input type="button" value="Remove" class="unm-remove-job" data-id="'+esc(j.id)+'">';
        body.append('<tr><td>'+esc(j.vm)+'</td><td>'+esc(j.peerName||j.peerId)+'</td><td>'+esc(j.state)+'</td><td><div class="unm-progress"><span style="width:'+parseInt(j.progress||0,10)+'%"></span></div><small>'+esc(j.progress||0)+'%</small></td><td>'+esc(j.message||'')+'</td><td>'+actions+'</td></tr>');
        
      }
      );
      if(!(r.jobs||[]).length)body.html('<tr><td colspan="6">No migration jobs yet.</td></tr>');
      
    }
    ).fail(err);
    
  }
  
function refreshLog() {
    if(!currentLogId)return;
    post(currentLogType==='seed'?'seedLog':'jobLog', currentLogType==='seed'?{seed_id:currentLogId}:{
      job_id:currentLogId
    }
    ).done(function(r) {
      var e=$('#unm-log-text');
      e.text(r.log||'(empty)');
      e.scrollTop(e[0].scrollHeight);
      
    }
    );
    
  }
  
function showLog(id,type) {
    currentLogId=id;currentLogType=type||'job';
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
    $(document).on('click','.unm-warm-prepare',function(){startWarmOperation($(this).attr('data-vm-id'),'prepare',this);});
    $(document).on('click','.unm-warm-update',function(){startWarmOperation($(this).attr('data-vm-id'),'update',this);});
    $(document).on('click','.unm-warm-cutover',function(){
      showPreflight($(this).attr('data-vm-id'),$(this).attr('data-vm-name'),null,{warm_seed_id:$(this).attr('data-seed-id'),migration_mode:'warm-cutover'},this);
    });
    $(document).on('click','.unm-resume-seed',function(){var b=this,stop=setButtonBusy(b,'Resuming');post('resumeWarm',{seed_id:$(this).attr('data-id')}).done(function(){flash('Prepared-copy resume started.');loadSeedsOnly();}).fail(err).always(stop);});
    $(document).on('click','.unm-remove-seed',function(){if(!confirm('Remove this prepared copy from the destination and release its ZFS snapshots?'))return;var b=this,stop=setButtonBusy(b,'Removing');post('removeWarm',{seed_id:$(this).attr('data-id')}).done(function(){flash('Prepared-copy removal started.');loadSeedsOnly();}).fail(err).always(stop);});
    $(document).on('click','.unm-seed-log',function(){showLog($(this).data('id'),'seed');});
    $('#unm-confirm-migrate').on('click',startMigration);
    $('#unm-peer-select').on('change',function(){renderSelectedPeerHealth();renderInventory();});
    $('#unm-recheck').on('click',recheckPreflight);
    $('.unm-modal-close').on('click',function() {
      $(this).closest('.unm-modal').removeClass('open');
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
      if(!confirm('Cancel this migration? The source will be restarted where it is safe to do so.'))return;
      post('cancelJob', {
        job_id:$(this).data('id')
      }
      ).done(function() {
        flash('Cancellation requested.');
        loadJobs();
        
      }
      ).fail(err);
      
    }
    );
    $(document).on('change','#unm-usb-action',updateMigrationEligibility);
    $(document).on('change','#unm-conflict-action',function(){if($(this).val()==='overwrite')recheckPreflight();else updateMigrationEligibility();});
    $(document).on('click','.unm-remove-job',function() {
      if(!confirm('Remove this completed/cancelled job and its local log? VM storage is not deleted.'))return;
      post('removeJob',{job_id:$(this).data('id')}).done(function(){flash('Job removed from history.');loadJobs();}).fail(err);
    });
    $('#unm-clear-jobs').on('click',function(){
      if(!confirm('Remove all eligible completed/cancelled jobs and their local logs? VM storage is not deleted.'))return;
      var stopBusy=setButtonBusy($(this),'Clearing');
      post('clearJobs').done(function(r){flash((r.removed||0)+' job(s) removed from history.');loadJobs();}).fail(err).always(stopBusy);
    });
    $('#unm-refresh').on('click',function() {
      loadPeers();
      loadInventory();
      loadJobs();
      
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
      if(!confirm('Break this pairing on both hosts and remove the dedicated SSH keys?'))return;
      post('breakPairing', {
        peer_id:$(this).data('id')
      }
      ).done(function() {
        flash('Pairing removed from both hosts where reachable.');
        loadPeers().then(discover);
        
      }
      ).fail(err);
      
    }
    );
    if($('#unm-job-body').length){setInterval(loadJobs,4000);setInterval(loadSeedsOnly,4000);}
    pollPeerHealth();healthTimer=setInterval(pollPeerHealth,Math.max(5,Number(appSettings.health_poll_seconds||10))*1000);
    document.addEventListener('visibilitychange',function(){if(!document.hidden){pollPeerHealth();if($('#unm-peer-list').length)loadPeers({skipHealth:true});}});
    
  }
  );
  

}
)(jQuery);
