/* SPDX-License-Identifier: GPL-3.0-only; Copyright (C) 2026 Richard Skinner */
(function($) {
  'use strict';
  var job='', serial=0, pending=null, filename='', version='', opener=null;
  function shareable() { return !pending && !!filename && !!$('#unm-report-text').val().trim() && $('#unm-report-reviewed').prop('checked'); }
  function updateButtons() { $('#unm-report-copy,#unm-report-download,#unm-report-github').prop('disabled',!shareable()); }
  function invalidate() {
    serial++;
    if(pending)pending.abort();
    pending=null; filename=''; version='';
    $('#unm-report-text').val(''); $('#unm-report-reviewed').prop('checked',false);
    $('#unm-report-generate').prop('disabled',false); updateButtons();
  }
  function closeReport() { invalidate(); job=''; $('#unm-report-modal').removeClass('open').attr('aria-hidden','true'); if(opener && document.contains(opener))opener.focus(); }
  $(function() {
    $(document).on('click','.unm-report-job',function() {
      invalidate(); job=String($(this).attr('data-id')||''); opener=this;
      $('#unm-report-paths').prop('checked',false); $('#unm-report-peer').prop('checked',true);
      $('#unm-report-status').text('Choose options, then generate. No information is sent to GitHub.');
      $('#unm-report-modal').addClass('open').attr('aria-hidden','false'); $('#unm-report-generate').trigger('focus');
    });
    $('#unm-report-paths,#unm-report-peer').on('change',function() { invalidate(); $('#unm-report-status').text('Options changed. Generate a new report and review it again.'); });
    $('#unm-report-generate').on('click',function() {
      if(!job)return;
      invalidate(); var requestSerial=serial;
      $('#unm-report-generate').prop('disabled',true);
      $('#unm-report-status').text('Collecting bounded, read-only diagnostics. This may take up to 20 seconds.');
      pending=$.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:25000,data:{action:'diagnosticReport',job_id:job,original_paths:$('#unm-report-paths').prop('checked')?'true':'false',include_peer:$('#unm-report-peer').prop('checked')?'true':'false'}})
        .done(function(response) {
          if(requestSerial!==serial)return;
          if(!response.success||!response.report||typeof response.report.text!=='string'){ $('#unm-report-status').text('The report could not be generated.'); return; }
          filename=/^unmotion-report-[0-9-]+\.txt$/.test(response.report.filename||'')?response.report.filename:'unmotion-report.txt';
          version=/^[0-9A-Za-z.-]{1,40}$/.test(response.report.version||'')?response.report.version:'unknown';
          $('#unm-report-text').val(response.report.text);
          $('#unm-report-status').text('Review and edit the report, then tick the review confirmation. Only the edited text is copied or downloaded.');
        }).fail(function(xhr,status) { if(requestSerial===serial && status!=='abort')$('#unm-report-status').text('Report unavailable or timed out. The job has not been changed.'); })
        .always(function() { if(requestSerial===serial){pending=null;$('#unm-report-generate').prop('disabled',false);updateButtons();} });
    });
    $('#unm-report-reviewed').on('change',updateButtons);
    $('#unm-report-text').on('input',function(){ $('#unm-report-reviewed').prop('checked',false); updateButtons(); });
    $('#unm-report-download').on('click',function() {
      if(!shareable())return;
      var url=URL.createObjectURL(new Blob([$('#unm-report-text').val()],{type:'text/plain;charset=utf-8'}));
      var link=document.createElement('a');link.href=url;link.download=filename;document.body.appendChild(link);link.click();link.remove();
      setTimeout(function(){URL.revokeObjectURL(url);},1000);
      $('#unm-report-status').text('Report downloaded locally. Attach it to your GitHub issue or share it through your support channel.');
    });
    $('#unm-report-copy').on('click',function() {
      if(!shareable())return;
      var text=$('#unm-report-text').val();
      function manualCopy(){var area=$('#unm-report-text')[0];area.focus();area.select();$('#unm-report-status').text('Report selected. Press Ctrl+C (or Command+C) to copy.');}
      if(navigator.clipboard && window.isSecureContext)navigator.clipboard.writeText(text).then(function(){$('#unm-report-status').text('Edited report copied.');},manualCopy);
      else manualCopy();
    });
    $('#unm-report-github').on('click',function() {
      if(!shareable())return;
      // No report, paths, identifiers or log content enters browser history/query strings.
      var title='unMotion '+version+' problem report';
      var body='## What happened?\nDescribe the problem and what you expected.\n\n## Diagnostics\nAttach the reviewed unmotion-report text file downloaded from unMotion, or paste the copied report here.\n\nDo not include credentials or information you do not want public.';
      window.open('https://github.com/rtho782/unmotion/issues/new?title='+encodeURIComponent(title)+'&body='+encodeURIComponent(body),'_blank','noopener,noreferrer');
      $('#unm-report-status').text('GitHub opened in a new tab. Sign in if prompted, attach your downloaded report and submit there. Your preview remains here; you can open GitHub again after login.');
    });
    $('#unm-report-modal .unm-modal-close').on('click',closeReport);
    $(document).on('keydown',function(e) {
      if(!$('#unm-report-modal').hasClass('open'))return;
      if(e.key==='Escape'){e.preventDefault();closeReport();}
      if(e.key==='Tab'){
        var controls=$('#unm-report-modal').find('input:not(:disabled),textarea,button:not(:disabled)').filter(':visible'),first=controls[0],last=controls[controls.length-1];
        if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
      }
    });
  });
})(jQuery);
