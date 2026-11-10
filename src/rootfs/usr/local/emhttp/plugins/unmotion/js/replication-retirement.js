// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($){
  'use strict';
  var serial=0,request=null,view=null,busy=false;
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function post(action,data){return $.ajax({url:'/plugins/unmotion/api.php',method:'POST',data:$.extend({action:action},data||{}),dataType:'json',timeout:60000});}
  function message(xhr){return (xhr.responseJSON&&xhr.responseJSON.error)||'Request failed. Refresh status before retrying.';}
  function close(){if(busy)return;serial++;if(request)request.abort();request=null;view=null;$('#unm-retire-modal').removeClass('open').attr('aria-hidden','true');}
  function choices(){var deleting=$('#unm-retire-mode').val()==='delete';$('#unm-retire-partial-row').toggle(deleting);if(!deleting)$('#unm-retire-partial').prop('checked',false);}
  $(document).on('click','.unm-end-replication',function(){
    if(busy)return;close();var mine=++serial,id=String($(this).attr('data-id'));
    $('#unm-retire-content').text('Reading the replication policy…');$('#unm-retire-submit').prop('disabled',true);$('#unm-retire-modal').addClass('open').attr('aria-hidden','false');
    request=post('reviewRetirement',{replication_id:id}).done(function(r){
      if(mine!==serial)return;view=r.retirement;var previous=view.retirement||{},retry=previous.token&&['ENDED_KEEP','ENDED_DELETE'].indexOf(previous.state)<0;
      var html='<p>End scheduled replication for <strong>'+esc(view.vmName)+'</strong>. New runs stop immediately; active work must settle before cleanup. The source VM and its writable disks are not deleted or powered off.</p><p>Recovery must be safely disarmed. Active/recovered or unresolved copies block completion; start fences are not bypassed.</p><ul>';
      (view.storage||[]).forEach(function(s){html+='<li>'+esc(s.kind)+': '+esc(s.source)+' → '+esc(s.destination)+'</li>';});
      html+='</ul><p>This choice covers this cycle’s policy-owned recovery points and any unfinished receive. Logs, ownership receipts and retained artifacts remain visible. Once ending completes, choose Replicate beside the VM to start a separate new cycle for the same destination. Old retained replicas are not reused or overwritten.</p><label>Destination data <select id="unm-retire-mode"><option value="keep">Keep replicas read-only</option><option value="delete">Delete replica data</option></select></label><p id="unm-retire-partial-row"><label><input type="checkbox" id="unm-retire-partial"> Also discard any partial receive and its resumable state</label></p><p id="unm-retire-error" role="status"></p>';
      $('#unm-retire-content').html(html);$('#unm-retire-mode').val(retry?previous.mode:(previous.state==='ENDED_KEEP'?'delete':'keep')).prop('disabled',!!retry);
      var caption=retry?'Retry retirement':previous.state==='ENDED_KEEP'?'Delete retained replicas':'End replication';
      $('#unm-retire-title').text(caption);
      // Unraid may replace input buttons with themed <button> elements. Update
      // both their value and visible text, rather than only the input value.
      $('#unm-retire-partial').prop('checked',!!(retry&&previous.discardPartial)).prop('disabled',!!retry&&!(previous.state==='BLOCKED'&&previous.mode==='delete'&&!previous.discardPartial&&!previous.remoteAck));choices();$('#unm-retire-submit').val(caption).text(caption).prop('disabled',previous.state==='ENDED_DELETE');
    }).fail(function(xhr){if(mine===serial&&xhr.statusText!=='abort')$('#unm-retire-content').text(message(xhr));});
  });
  $(document).on('change','#unm-retire-mode',choices);
  $(document).on('click','.unm-retire-close',close);
  $(document).on('click','#unm-retire-submit',function(){
    if(busy||!view||$(this).prop('disabled'))return;
    var mine=serial,data={replication_id:view.id,review:view.review,mode:$('#unm-retire-mode').val(),discard_partial:$('#unm-retire-partial').prop('checked')?'1':'0'};
    busy=true;$('#unm-retire-submit,.unm-retire-close').prop('disabled',true);
    request=post('endReplication',data).done(function(r){if(mine!==serial)return;$('#unm-retire-content').text(r.retirement.message||'Retirement requested. Follow its status in Scheduled Replication.');view=null;$(document).trigger('unmotion:replication-change');}).fail(function(xhr){if(mine===serial)$('#unm-retire-error').text(message(xhr));}).always(function(){busy=false;$('.unm-retire-close').prop('disabled',false);if(view)$('#unm-retire-submit').prop('disabled',false);});
  });
})(jQuery);
