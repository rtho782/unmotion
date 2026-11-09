// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($){
  'use strict';
  var serial=0,request=null,selection=null,submitting=false,polling=false,logId='',logText='',logRequest=null;
  var terminal=['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'];
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function post(action,data){return $.ajax({url:'/plugins/unmotion/api.php',method:'POST',data:$.extend({action:action},data||{}),dataType:'json',timeout:60000});}
  function error(xhr){return (xhr.responseJSON&&xhr.responseJSON.error)||'Request failed. Refresh status before retrying a mutation.';}
  function show(message){$('#unm-live-content').text(message);$('#unm-live-modal').addClass('open').attr('aria-hidden','false');}
  function close(){if(submitting)return;serial++;if(request&&request.readyState!==4)request.abort();if(logRequest&&logRequest.readyState!==4)logRequest.abort();request=null;selection=null;logId='';$('#unm-live-modal').removeClass('open').attr('aria-hidden','true');$('#unm-live-start').prop('disabled',true).hide();}
  function preview(button){
    if(submitting)return;close();var peer=$('#unm-peer-select').val();if(!peer){$('#unm-live-notice').text('Select a paired destination first.');return;}
    var mine=++serial;selection={vm_id:$(button).attr('data-vm-id'),peer_id:peer};$('#unm-live-title').text('Live migrate '+$(button).attr('data-vm-name'));$('#unm-live-start').show().prop('disabled',true);
    show('Checking the running CPU mode, exact machine, guest devices, storage and destination… No VM changes have been made.');
    request=post('livePreview',selection).done(function(r){if(mine!==serial)return;if(!r.success){show(r.error||'Preflight failed.');return;}var p=r.preview;
      $('#unm-live-content').html('<p>'+esc(p.vcpus)+' vCPUs · '+esc(p.memoryMiB)+' MiB RAM · '+esc(p.diskGiB)+' GiB '+esc(p.format)+' · '+esc(p.machine)+'</p><p>Disk and RAM copy while the guest runs. The source then stops executing; only after its durable start fence is confirmed may the destination resume. The guest UUID, MAC addresses, CPU profile and resources are retained.</p><p>The encrypted link and guest RAM dirty rate are measured before copying. At least 4× bandwidth headroom is recommended; guest disk writes and other traffic also matter.</p><ul>'+p.warnings.map(function(w){return '<li>'+esc(w)+'</li>';}).join('')+'</ul><p>Native autostart is disabled on the source. Unresolved/retired source starts remain fenced across reboot. This beta retains old/partial disks and supports one live migration at a time per host.</p>');$('#unm-live-start').prop('disabled',false);
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
        var cells=row.children('td'),text=[j.vmName,j.role,stopped?'ATTENTION':j.state,(stopped?'Worker stopped; use Reconcile. ':'')+(j.message||'')+(j.state==='COPYING'&&j.progress!=null?' ('+j.progress+'%)':'')];text.forEach(function(v,i){if(cells.eq(i).text()!==String(v||''))cells.eq(i).text(v||'');});
        var actions='<input type="button" class="unm-live-log" data-id="'+esc(j.id)+'" value="Log">';
        if(j.role==='source'&&terminal.indexOf(j.state)<0){actions+=' <input type="button" class="unm-live-reconcile" data-id="'+esc(j.id)+'" value="Reconcile" '+(j.workerActive?'disabled title="Wait for the active worker or request cancellation"':'')+'>';if(!stopped&&['QUEUED','PREFLIGHT','SOURCE_MOVING','COPYING'].indexOf(j.state)>=0)actions+=' <input type="button" class="unm-live-cancel" data-id="'+esc(j.id)+'" value="Request cancellation">';}
        if(cells.eq(4).data('actions')!==actions)cells.eq(4).html(actions).data('actions',actions);
      });
      body.find('tr[data-live-id]').each(function(){if(!seen[$(this).attr('data-live-id')])$(this).remove();});if(!(r.jobs||[]).length)body.html('<tr><td colspan="5">No live migration jobs.</td></tr>');
    }).fail(function(){ $('#unm-live-notice').text('Live job refresh failed; last observations are retained.'); }).always(function(){polling=false;});
  }
  $(function(){
    if(!$('#unm-live-body').length)return;
    $(document).on('click','.unm-live-migrate',function(){preview(this);}).on('click','.unm-live-log',function(){readLog($(this).attr('data-id'));});
    $('.unm-live-close').on('click',close);
    $('#unm-live-start').on('click',function(){if(!selection||submitting||$(this).prop('disabled'))return;var data=$.extend({},selection),mine=serial;submitting=true;$(this).prop('disabled',true);$('.unm-live-close').prop('disabled',true);show('Starting the job. Fresh checks run again before any transfer.');
      post('liveStart',data).done(function(r){if(mine!==serial)return;show(r.success?'Job '+r.job.id+' started. You can close this dialog and follow Live migration below.':r.error);selection=null;$('#unm-live-start').hide();refresh();}).fail(function(xhr){if(mine===serial)show(error(xhr)+' Check Live migration before trying again.');selection=null;}).always(function(){submitting=false;$('.unm-live-close').prop('disabled',false);});
    });
    $(document).on('click','.unm-live-cancel,.unm-live-reconcile',function(){var button=$(this),id=button.attr('data-id'),action=button.hasClass('unm-live-cancel')?'liveCancel':'liveReconcile';button.prop('disabled',true);post(action,{id:id}).done(function(r){$('#unm-live-notice').text(r.success?'Request completed; refreshing live status.':r.error);refresh();}).fail(function(xhr){$('#unm-live-notice').text(error(xhr));}).always(function(){button.prop('disabled',false);});});
    refresh();setInterval(function(){refresh();refreshLog();},3000);
  });
})(jQuery);
