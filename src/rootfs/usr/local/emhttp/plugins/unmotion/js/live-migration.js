// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($){
  'use strict';
  var serial=0,request=null,selection=null,submitting=false,polling=false,logId='',logText='',logRequest=null,handback=null;
  var terminal=['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'];
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function gib(n){return (Number(n||0)/1073741824).toFixed(2)+' GiB';}
  function transferText(p){if(!p)return '';var parts=[String(p.phase||'COPYING').replace(/_/g,' ')];['disk','memory'].forEach(function(k){var s=p[k];if(!s)return;if(k==='memory'&&!s.totalBytes&&!s.remainingBytes&&!s.processedBytes){parts.push('RAM: awaiting precopy');return;}parts.push((k==='disk'?'Disk':'RAM')+': '+(s.remainingBytes!=null?gib(s.remainingBytes)+' remaining':'awaiting remaining bytes')+(s.totalBytes!=null?' / '+gib(s.totalBytes):''));});if(p.ramIteration>0)parts.push('RAM pass '+p.ramIteration);if(p.observedAt&&Date.now()-Date.parse(p.observedAt)>15000)parts.push('last observed '+p.observedAt);return ' · '+parts.join(' · ');}
  function capacityHtml(p){if(!p.capacity)return '';return '<p>Destination storage: '+(p.disks||[]).map(function(d){return esc(d.target)+': '+esc(d.kind||'file')+' '+esc(d.sizeGiB)+' GiB';}).join(' · ')+'</p><ul>'+(p.capacity.groups||[]).map(function(g){return '<li>'+esc(g.group)+': '+esc(gib(g.requiredBytes))+' budget, '+esc(gib(g.availableBytes))+' available; '+esc(gib(g.headroomBytes))+' kept as free-space headroom.</li>';}).join('')+'</ul><p>Zvols reserve their full size; file images stay sparse. Source and partial copies keep consuming space until explicitly removed. This is capacity planning, not a network-byte estimate or ETA.</p>';}
  function post(action,data){return $.ajax({url:'/plugins/unmotion/api.php',method:'POST',data:$.extend({action:action},data||{}),dataType:'json',timeout:60000});}
  function error(xhr){return (xhr.responseJSON&&xhr.responseJSON.error)||'Request failed. Refresh status before retrying a mutation.';}
  function show(message){$('#unm-live-content').text(message);$('#unm-live-modal').addClass('open').attr('aria-hidden','false');}
  function close(){if(submitting)return;serial++;if(request&&request.readyState!==4)request.abort();if(logRequest&&logRequest.readyState!==4)logRequest.abort();request=null;selection=null;handback=null;logId='';$('#unm-live-modal').removeClass('open').attr('aria-hidden','true');$('#unm-live-start').prop('disabled',true).val('Start live migration').hide();}
  function reviewHandback(id){
    if(submitting)return;close();var mine=++serial;$('#unm-live-title').text('Return to ordinary workflows');$('#unm-live-start').show().val('Confirm hand-back').prop('disabled',true);show('Proving the local owner is powered off and the peer retains its non-owner start fence…');
    request=post('liveHandbackPreview',{id:id}).done(function(r){if(mine!==serial)return;if(!r.success){show(r.error);return;}handback={id:id,review:r.review};show('Return '+r.vmName+' to Cold/Warm Move, clone, CPU editing and replication-only configuration. Recovery arming remains unavailable. This host remains the owner. The peer’s old copy stays fenced. Hand-back does not start VMs or delete files/disks. Ordinary transfers of this VM require Beta5 or later on both hosts.');$('#unm-live-start').prop('disabled',false);}).fail(function(xhr,status){if(mine===serial&&status!=='abort')show(error(xhr));});
  }
  function preview(button){
    if(submitting)return;close();var peer=$('#unm-peer-select').val();if(!peer){$('#unm-live-notice').text('Select a paired destination first.');return;}
    var mine=++serial;selection={vm_id:$(button).attr('data-vm-id'),peer_id:peer};$('#unm-live-title').text('Live migrate '+$(button).attr('data-vm-name'));$('#unm-live-start').show().prop('disabled',true);
    show('Checking the running CPU mode, exact machine, guest devices, storage and destination… No VM changes have been made.');
    request=post('livePreview',selection).done(function(r){if(mine!==serial)return;if(!r.success){show(r.error||'Preflight failed.');return;}
      if(r.ready===false){$('#unm-live-content').html('<p>Not ready for live migration. No VM changes have been made.</p><ul>'+(r.blockers||[]).map(function(b){return '<li>'+esc(b.message)+'</li>';}).join('')+'</ul><p>'+esc(r.alternative)+'</p><p>Observed: '+esc(r.observedAt)+'</p><input type="button" class="unm-live-recheck" data-vm-id="'+esc(selection.vm_id)+'" data-vm-name="'+esc($(button).attr('data-vm-name'))+'" value="Recheck readiness">');return;}var p=r.preview;
      $('#unm-live-content').html('<p>'+esc(p.vcpus)+' vCPUs · '+esc(p.memoryMiB)+' MiB RAM · '+esc(p.diskCount||1)+' disk(s), '+esc(p.diskGiB)+' GiB '+esc(p.format)+' · '+esc(p.machine)+' · TPM: '+esc(p.tpm||'none')+'</p><p>All writable disks, RAM and supported software TPM state transfer while the guest runs. The source then stops executing; only after its durable start fence is confirmed may the destination resume. The guest UUID, MAC addresses, CPU profile and resources are retained.</p><p>The encrypted link and guest RAM dirty rate are measured before copying. At least 4× bandwidth headroom is recommended; guest disk writes and other traffic also matter.</p><ul>'+p.warnings.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul><p>Native autostart is disabled on the source. Unresolved/retired source starts remain fenced across reboot. This beta retains old/partial disks and TPM state and supports one live migration at a time per host.</p>');$('#unm-live-start').prop('disabled',false);
      if(p&&p.capacity)$('#unm-live-content').append(capacityHtml(p));
    }).fail(function(xhr,status){if(mine===serial&&status!=='abort')show(error(xhr));});
  }
  function readLog(id){if(submitting)return;close();var mine=++serial;logId=id;logText='';$('#unm-live-title').text('Live migration log');$('#unm-live-start').hide();show('Reading log…');
    request=post('liveLog',{id:id}).done(function(r){if(mine!==serial)return;logText=r.log||'';$('#unm-live-content').empty().append($('<pre class="unm-pre">').text(logText));}).fail(function(xhr,status){if(mine===serial&&status!=='abort')show(error(xhr));});
  }
  function refreshLog(){
    if(!logId||logRequest||document.hidden)return;var mine=serial,id=logId;
    logRequest=post('liveLog',{id:id}).done(function(r){if(mine!==serial||id!==logId||!r.success||r.log===logText)return;var pre=$('#unm-live-content pre')[0];if(!pre)return;
      var selection=window.getSelection();if(selection&&selection.toString()&&pre.contains(selection.anchorNode))return;
      var follow=pre.scrollHeight-pre.scrollTop-pre.clientHeight<24,top=pre.scrollTop,next=r.log||'';
      if(next.indexOf(logText)===0)pre.appendChild(document.createTextNode(next.slice(logText.length)));else pre.textContent=next;logText=next;pre.scrollTop=follow?pre.scrollHeight:top;
    }).always(function(){logRequest=null;});
  }
  function refresh(){
    if(polling||document.hidden||!$('#unm-live-body').length)return;polling=true;
    post('liveJobs').done(function(r){if(!r.success)return;var body=$('#unm-live-body'),seen={};
      (r.jobs||[]).forEach(function(j){seen[j.id]=true;var row=body.find('tr[data-live-id="'+j.id+'"]');if(!row.length){body.find('tr:not([data-live-id])').remove();row=$('<tr>').attr('data-live-id',j.id).append('<td></td><td></td><td></td><td></td><td></td>');body.append(row);}
        var stopped=j.role==='source'&&j.workerActive===false&&terminal.indexOf(j.state)<0&&j.state!=='QUEUED';
        var cells=row.children('td'),text=[j.vmName,j.role,stopped?'ATTENTION':j.state,(stopped?'Worker stopped; use Reconcile. ':'')+(j.message||'')+(j.state==='COPYING'?transferText(j.transfer):'')+(j.receivedMiB>0?' · '+j.receivedMiB+' MiB received over transport':'')];text.forEach(function(v,i){if(cells.eq(i).text()!==String(v||''))cells.eq(i).text(v||'');});
        var actions='<input type="button" class="unm-live-log" data-id="'+esc(j.id)+'" value="Log">';
        if(j.canHandback)actions+=' <input type="button" class="unm-live-handback" data-id="'+esc(j.id)+'" value="Return to ordinary workflows">';
        if(j.role==='source'&&terminal.indexOf(j.state)<0){actions+=' <input type="button" class="unm-live-reconcile" data-id="'+esc(j.id)+'" value="Reconcile" '+(j.workerActive?'disabled title="Wait for the active worker or request cancellation"':'')+'>';if(!stopped&&['QUEUED','PREFLIGHT','SOURCE_MOVING','COPYING'].indexOf(j.state)>=0)actions+=' <input type="button" class="unm-live-cancel" data-id="'+esc(j.id)+'" value="Request cancellation">';}
        if(cells.eq(4).data('actions')!==actions)cells.eq(4).html(actions).data('actions',actions);
      });
      body.find('tr[data-live-id]').each(function(){if(!seen[$(this).attr('data-live-id')])$(this).remove();});if(!(r.jobs||[]).length)body.html('<tr><td colspan="5">No live migration jobs.</td></tr>');
    }).fail(function(){ $('#unm-live-notice').text('Live job refresh failed; last observations are retained.'); }).always(function(){polling=false;});
  }
  $(function(){
    if(!$('#unm-live-body').length)return;
    $(document).on('click','.unm-live-migrate,.unm-live-recheck',function(){preview(this);}).on('click','.unm-live-log',function(){readLog($(this).attr('data-id'));});
    $(document).on('click','.unm-live-handback',function(){reviewHandback($(this).attr('data-id'));});
    $('.unm-live-close').on('click',close);
    $('#unm-live-start').on('click',function(){
      if(handback&&!submitting&&!$(this).prop('disabled')){var reviewed=handback,mine=serial;submitting=true;$(this).prop('disabled',true);$('.unm-live-close').prop('disabled',true);show('Rechecking both hosts and retaining the non-owner fence before returning ordinary controls…');post('liveHandback',reviewed).done(function(r){if(mine===serial)show(r.success?'Hand-back complete. Ordinary workflows are available on the owner; the non-owner remains fenced.':r.error);refresh();}).fail(function(xhr){if(mine===serial)show(error(xhr)+' Retry the reviewed hand-back; do not delete authority files.');}).always(function(){submitting=false;handback=null;$('.unm-live-close').prop('disabled',false);$('#unm-live-start').hide();});return;}
      if(!selection||submitting||$(this).prop('disabled'))return;var data=$.extend({},selection),mine=serial;submitting=true;$(this).prop('disabled',true);$('.unm-live-close').prop('disabled',true);show('Starting the job. Fresh checks run again before any transfer.');
      post('liveStart',data).done(function(r){if(mine!==serial)return;show(r.success?'Job '+r.job.id+' started. You can close this dialog and follow Live migration below.':r.error);selection=null;$('#unm-live-start').hide();refresh();}).fail(function(xhr){if(mine===serial)show(error(xhr)+' Check Live migration before trying again.');selection=null;}).always(function(){submitting=false;$('.unm-live-close').prop('disabled',false);});
    });
    $(document).on('click','.unm-live-cancel,.unm-live-reconcile',function(){var button=$(this),id=button.attr('data-id'),action=button.hasClass('unm-live-cancel')?'liveCancel':'liveReconcile';button.prop('disabled',true);post(action,{id:id}).done(function(r){$('#unm-live-notice').text(r.success?'Request completed; refreshing live status.':r.error);refresh();}).fail(function(xhr){$('#unm-live-notice').text(error(xhr));}).always(function(){button.prop('disabled',false);});});
    refresh();setInterval(function(){refresh();refreshLog();},3000);
  });
})(jQuery);
