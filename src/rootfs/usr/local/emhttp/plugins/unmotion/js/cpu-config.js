// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($){
  'use strict';
  var serial=0,request=null,selection='',review=null,applying=false,opener=null;
  function content(message){return $('#unm-cpu-config-content').empty().append($('<p>').text(message));}
  function close(){
    if(applying)return;
    serial++;if(request)request.abort();request=null;review=null;
    $('#unm-cpu-config-modal').removeClass('open').attr('aria-hidden','true');
    if(opener)opener.focus();
  }
  function rows(value){
    var target=$('#unm-cpu-config-content');
    (value.vms||[]).forEach(function(vm){$('<p>').text(vm.name+': '+vm.status+(vm.reason?' — '+vm.reason:'')).appendTo(target);});
  }
  function send(action,extra,done,failed){
    var ticket=serial;
    request=$.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:180000,data:Object.assign({},JSON.parse(selection),extra,{action:action})});
    request.done(function(value){if(ticket===serial){if(value.success)done(value);else failed(value.error);}});
    request.fail(function(xhr){if(ticket===serial&&xhr.statusText!=='abort')failed(xhr.responseJSON&&xhr.responseJSON.error);});
    request.always(function(){if(ticket===serial)request=null;});
  }
  function preview(){
    if(applying)return;
    selection=$('#unm-cpu-selection').val();if(!selection)return;
    serial++;if(request)request.abort();review=null;opener=document.activeElement;
    $('#unm-cpu-config-confirm').prop('disabled',true).show();
    $('#unm-cpu-config-cancel').prop('disabled',false).val('Cancel');
    $('#unm-cpu-config-modal').addClass('open').attr('aria-hidden','false');
    content('Checking persistent VM XMLs and validating '+JSON.parse(selection).mode+' against their actual machine versions…');
    $('#unm-cpu-config-cancel').trigger('focus');
    send('cpuConfigPreview',{},function(value){
      if(selection!==$('#unm-cpu-selection').val()){content('The CPU selection changed. Close this review and try again.');return;}
      review=value.review;
      content(value.eligible+' VM(s) will use '+value.mode+' after their next full stop/start. Running guests are unchanged. Paired hosts are not modified.');
      $('<p>').text('Older modes can reduce performance or omit instructions required by the guest. Existing VMX/SVM exposure is preserved and validated locally; an ordinary shared mask does not by itself prove nested migration compatibility. Original XMLs are backed up. Skipped VMs are not changed.').appendTo('#unm-cpu-config-content');
      rows(value);$('#unm-cpu-config-confirm').prop('disabled',!value.eligible);
    },function(error){content(error||'CPU configuration could not be reviewed. No VM was changed.');});
  }
  function apply(){
    if(applying||!review)return;
    if(selection!==$('#unm-cpu-selection').val()){review=null;$('#unm-cpu-config-confirm').prop('disabled',true);content('The CPU selection changed. Close this review and try again.');return;}
    applying=true;$('#unm-cpu-config-confirm').prop('disabled',true);$('#unm-cpu-config-cancel').prop('disabled',true);
    content('Rechecking the reviewed XMLs, saving originals and applying persistent CPU definitions. Running guests are unchanged…');
    function finished(){applying=false;review=null;$('#unm-cpu-config-confirm').hide();$('#unm-cpu-config-cancel').prop('disabled',false).val('Close').trigger('focus');}
    send('cpuConfigApply',{review:review},function(value){
      content(value.applied+' VM(s) updated.'+(value.complete?'':' The batch stopped; inspect the entries below before retrying.')+' Changes take effect after a full stop/start, not a guest reboot.');
      if(value.backupDirectory)$('<p>').text('Original XML backups and batch result: '+value.backupDirectory).appendTo('#unm-cpu-config-content');
      rows(value);finished();
    },function(error){content(error?error+' Inspect the VM definitions and CPU backup batch results before retrying.':'Connection lost; some XMLs may have been updated. Inspect the VM definitions and /boot/config/plugins/unmotion/cpu-config-backups/ before retrying.');finished();});
  }
  $(function(){
    var defaultBusy=false,defaultConfigured=false,defaultSerial=0;
    function showDefault(value){
      var current=value.default||{},editor=current.editor||{};
      defaultConfigured=!!current.configured;
      $('#unm-cpu-default-status').text(current.error||(!editor.available?editor.error:(current.configured?'New VM default: '+current.mode+'. Existing VMs are unchanged.':'No unMotion CPU default is set.')));
      $('#unm-cpu-default-clear').prop('disabled',!current.configured||defaultBusy);
    }
    function saveDefault(clear){
      if(defaultBusy)return;var chosen=$('#unm-cpu-selection').val();if(!clear&&!chosen)return;
      defaultBusy=true;defaultSerial++;$('#unm-cpu-default, #unm-cpu-default-clear').prop('disabled',true);$('#unm-cpu-default-status').text('Validating and saving the default for new VMs…');
      $.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:45000,data:Object.assign(clear?{clear:'1'}:JSON.parse(chosen),{action:'cpuDefaultSave'})}).done(function(value){
        if(value.success)showDefault(value);else $('#unm-cpu-default-status').text(value.error||'The CPU default could not be saved.');
      }).fail(function(xhr){$('#unm-cpu-default-status').text((xhr.responseJSON||{}).error||'Connection lost. Reload to check the saved default.');}).always(function(){defaultBusy=false;$('#unm-cpu-default').prop('disabled',!$('#unm-cpu-selection').val());$('#unm-cpu-default-clear').prop('disabled',!defaultConfigured);});
    }
    $('#unm-cpu-default').on('click',function(){saveDefault(false);});$('#unm-cpu-default-clear').on('click',function(){saveDefault(true);});
    var defaultTicket=defaultSerial;
    $.ajax({url:'/plugins/unmotion/api.php',method:'GET',dataType:'json',data:{action:'cpuDefault'}}).done(function(value){if(defaultTicket===defaultSerial)showDefault(value);}).fail(function(){if(defaultTicket===defaultSerial)$('#unm-cpu-default-status').text('The current CPU default could not be read. Reload to check it.');});
    $('#unm-cpu-apply').on('click',preview);$('#unm-cpu-config-confirm').on('click',apply);$('#unm-cpu-config-cancel').on('click',close);
    $('#unm-cpu-config-modal').on('keydown',function(event){
      if(event.key==='Escape'){event.preventDefault();close();}
      if(event.key==='Tab'){
        var buttons=$(this).find('input:visible:enabled');if(!buttons.length){event.preventDefault();return;}
        var first=buttons[0],last=buttons[buttons.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
      }
    });
  });
})(jQuery);
