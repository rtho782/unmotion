// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
(function($) {
  'use strict';
  var serial=0,requests=[],timer=null,report=null,peers=[],peerListReady=false,peerSnapshotFromCpu=false,levelChosen=false;
  // A presentation preference, not a feature-superset or performance claim.
  // Unknown future families stay visible, but are never guessed into a suggestion.
  var families={
    Intel:['DiamondRapids','ClearwaterForest','GraniteRapids','SierraForest','SapphireRapids','Icelake-Server','Cooperlake','Icelake-Client','Cascadelake-Server','Snowridge','Skylake-Server','Skylake-Client','Denverton','Broadwell','Haswell','IvyBridge','SandyBridge','Westmere','Nehalem','Penryn','Conroe'],
    AMD:['EPYC-Turin','EPYC-Genoa','EPYC-Milan','EPYC-Rome','EPYC','Opteron_G5','Opteron_G4','Opteron_G3','Opteron_G2','Opteron_G1'],
    Hygon:['Dhyana']
  };
  var legacy=['n270','phenom','core2duo','coreduo','athlon','pentium3','pentium2','pentium','486'];
  // Synthetic baselines have no hardware-generation chronology. Keep them last.
  var generic=['kvm64','qemu64','kvm32','qemu32'];
  function cpuPreference(mode,vendor) {
    var match=/^(.*)-v([1-9][0-9]*)$/.exec(mode.id),family=match?match[1]:mode.id;
    var order=Object.prototype.hasOwnProperty.call(families,vendor)?families[vendor]:[],index=order.indexOf(family),known=Object.keys(families).some(function(v){return families[v].indexOf(family)!==-1;});
    var revision=match?Number(match[2]):0,oldIndex=legacy.indexOf(family),genericIndex=generic.indexOf(family);
    return {family:family,bucket:index!==-1?3:known?1:oldIndex!==-1?0:genericIndex!==-1?-1:2,rank:index!==-1?order.length-index:oldIndex!==-1?legacy.length-oldIndex:genericIndex!==-1?generic.length-genericIndex:0,revision:Number.isFinite(revision)?revision:0};
  }
  function sortedCpuModes(local) {
    var vendor=(local.context||{}).vendor;
    return (Array.isArray(local.modes)?local.modes:[]).filter(function(mode){return mode&&typeof mode.id==='string'&&mode.id.length<=192&&mode.status==='supported';}).slice().sort(function(a,b){
      var x=cpuPreference(a,vendor),y=cpuPreference(b,vendor);
      return Number(!!a.deprecated)-Number(!!b.deprecated)||y.bucket-x.bucket||y.rank-x.rank||(x.family===y.family?y.revision-x.revision:0)||a.id.localeCompare(b.id,'en',{numeric:true});
    });
  }
  function commonCpuMode(value,mode) {
    return value.scopeReady&&(value.peers||[]).every(function(peer){
      return peer.complete&&!peer.error&&(peer.levels||[]).some(function(row){return row.id===mode.id&&row.common===true;});
    });
  }
  function cpuFamilyModes(value,supported) {
    var groups=new Map();
    supported.forEach(function(mode){
      var family=cpuPreference(mode,(value.local.context||{}).vendor).family;
      if(!groups.has(family))groups.set(family,[]);
      groups.get(family).push(mode);
    });
    // Keep an unavailable family visible using its local top revision. Never
    // infer a common mask from version numbers or hide it as an empty success.
    return Array.from(groups.values()).map(function(modes){return modes.find(function(mode){return commonCpuMode(value,mode);})||modes[0];});
  }
  function cpuVersionGuidance(value) {
    if(!value.scopeReady||!value.local.available||!(value.peers||[]).length||value.peers.some(function(peer){return !peer.complete||peer.error;}))return '';
    function top(local){
      var modes=new Map();sortedCpuModes(local).forEach(function(mode){
        var preference=cpuPreference(mode,(local.context||{}).vendor);
        if(preference.bucket===3&&!mode.deprecated&&!modes.has(preference.family))modes.set(preference.family,mode.id);
      });return modes;
    }
    var local=top(value.local),different=value.peers.some(function(peer){
      if(!peer.report||!peer.report.available||!peer.report.context)return false;
      if(['architecture','virtType','machine','vendor'].some(function(field){return !value.local.context[field]||value.local.context[field]!==peer.report.context[field];}))return false;
      var remote=top(peer.report);
      return Array.from(local).some(function(entry){
        var other=remote.get(entry[0]);if(!other)return false;
        var row=(peer.levels||[]).find(function(row){return row.id===entry[1];});
        return entry[1]!==other||row&&row.common===false&&row.peerStatus==='supported';
      });
    });
    return different?'Hosts report different validated CPU revisions or definitions. Check Unraid updates on older installations to align QEMU/libvirt; firmware, microcode and CPU feature settings can also differ. An update is not guaranteed to resolve the difference.':'';
  }
  function cpuSuggestion(value,supported) {
    if(!value.local.available)return {text:'No suggestion until local CPU results are available.'};
    if(!value.scopeReady)return {text:value.scopeError||'Waiting for the paired-host list before suggesting a level.'};
    if((value.peers||[]).some(function(peer){return !peer.complete;}))return {text:'Waiting for every selected peer before suggesting a common level.'};
    var best=supported.find(function(mode){
      return !mode.deprecated&&cpuPreference(mode,(value.local.context||{}).vendor).bucket===3&&commonCpuMode(value,mode);
    });
    if(!best)return {text:'No suggestion: no ranked, non-deprecated level is confirmed on every selected host. You can inspect individual peers or choose a level manually.'};
    return {id:best.id,text:(value.peers||[]).length?'Suggested: '+best.id+' — newest recognised validated level shared by this host and '+(value.peers.length===1?'the selected peer.':'all '+value.peers.length+' selected peers.'):'Suggested locally: '+best.id+' — no paired-host compatibility is implied.'};
  }
  function setCpuPeers(items) {
    var select=$('#unm-cpu-peer'),selected=select.val();
    peers=items.filter(function(peer){return peer.pairingState==='paired';});peerListReady=true;
    select.empty();$('<option>').val('*').text('All paired hosts').appendTo(select);$('<option>').val('').text('Local host only').appendTo(select);
    peers.forEach(function(peer){$('<option>').val(peer.id).text(peer.name+' ('+peer.host+')').appendTo(select);});
    if(selected&&selected!=='*'&&!peers.some(function(peer){return peer.id===selected;}))$('<option>').val(selected).text('Previously selected peer — no longer paired').appendTo(select);
    select.val(selected);
  }
  function showCpuScope(value) {
    var target=$('#unm-cpu-scope-results').empty();
    if(!value.success){$('<p>').text(value.error||'Saved VM scope could not be inspected. Host CPU comparison is still available.').appendTo(target);return;}
    if(value.error)$('<p>').text(value.error).appendTo(target);
    (value.unsupportedVms||[]).forEach(function(vm){$('<p>').text(vm.name+' ('+vm.machine+'): '+vm.reason+' Existing cold/warm moves are unchanged.').appendTo(target);});
  }
  function clearCpuCompatibility() {
    serial++;
    if(timer!==null)clearTimeout(timer);
    timer=null;
    var previous=requests;requests=[];previous.forEach(function(request){request.abort();});
    report=null;levelChosen=false;
    $('#unm-cpu-selection').val('');$('#unm-cpu-apply').prop('disabled',true);
    $('#unm-cpu-level').empty().prop('disabled',true);
    $('#unm-cpu-result').empty();$('#unm-cpu-matrix').empty();
    $('#unm-cpu-check').prop('disabled',false).val('Read cached results');
    $('#unm-cpu-status').text('Choose paired hosts, then read cached CPU results.');
    $('#unm-cpu-suggestion').empty();
    $('#unm-cpu-revision-note').empty();$('#unm-cpu-version-guidance').empty();
  }
  function post(data,ticket,done,failed) {
    var request=$.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:65000,data:data});
    requests.push(request);
    request.done(function(value){if(ticket===serial)done(value);});
    request.fail(function(xhr){if(ticket===serial&&xhr.statusText!=='abort')failed(xhr.responseJSON);});
    request.always(function(){requests=requests.filter(function(item){return item!==request;});});
  }
  function renderCpuLevel() {
    var id=$('#unm-cpu-level').val(),mode=report&&(report.local.modes||[]).find(function(item){return item.id===id;});
    var result=$('#unm-cpu-result').empty();
    var canApply=!!(mode&&mode.definitionHash&&mode.cpuXml&&commonCpuMode(report,mode));
    $('#unm-cpu-selection').val(canApply?JSON.stringify({mode:mode.id,definition_hash:mode.definitionHash,cache_identity:report.local.cache.identity}):'');
    $('#unm-cpu-apply').prop('disabled',!canApply);
    if(!mode)return;
    $('<p>').text('Local: '+mode.status+'. Cached '+(report.local.overviewVersion?'Q35/i440fx host overview':'for '+report.local.context.machine)+' on '+report.local.hostname+' at '+report.local.observedAt+'.').appendTo(result);
    (report.peers||[]).forEach(function(peer){
      var row=(peer.levels||[]).find(function(item){return item.id===id;});
      $('<p>').text(peer.name+': '+(row?row.reason:(peer.error||'Peer results are unavailable.'))).appendTo(result);
    });
    if(mode.deprecated)$('<p>').text('This CPU level is deprecated by the local hypervisor.').appendTo(result);
    if(mode.cpuXml)$('<details>').append($('<summary>').text('Cached validated CPU definition')).append($('<pre>').addClass('unm-pre').text(mode.cpuXml.replace(/></g,'>\n<'))).appendTo(result);
  }
  function showCpuCompatibility(value,preserveInspection) {
    var selected=$('#unm-cpu-level').val();report=value;
    var select=$('#unm-cpu-level').empty(),supported=sortedCpuModes(value.local),suggestion=cpuSuggestion(value,supported);
    var showAll=$('#unm-cpu-all-revisions').prop('checked')===true,visible=showAll?supported:cpuFamilyModes(value,supported),note='';
    if(!showAll&&levelChosen&&supported.some(function(mode){return mode.id===selected;})&&!visible.some(function(mode){return mode.id===selected;})){
      if(preserveInspection!==false){
        $('#unm-cpu-all-revisions').prop('checked',true);showAll=true;visible=supported;
        note='Showing all revisions to keep the level you are inspecting visible.';
      }else{
        var family=cpuPreference({id:selected},(value.local.context||{}).vendor).family;
        var replacement=visible.find(function(mode){return cpuPreference(mode,(value.local.context||{}).vendor).family===family;});
        selected=replacement?replacement.id:'';if(!replacement)levelChosen=false;
      }
    }
    $('#unm-cpu-revision-note').text(note);
    $('#unm-cpu-version-guidance').text(cpuVersionGuidance(value));
    $('#unm-cpu-suggestion').text(suggestion.text);
    visible.forEach(function(mode){
      var common=(value.peers||[]).filter(function(peer){return (peer.levels||[]).some(function(row){return row.id===mode.id&&row.common===true;});}).map(function(peer){return peer.name;});
      $('<option>').val(mode.id).text(mode.id+(suggestion.id===mode.id?' — suggested':'')+(mode.deprecated?' — deprecated':'')+(cpuPreference(mode,(value.local.context||{}).vendor).bucket===2?' — unranked family':'')+(common.length?' — common with '+common.join(', '):'')).appendTo(select);
    });
    select.prop('disabled',!visible.length);
    if(!levelChosen&&suggestion.id)select.val(suggestion.id);
    else if(visible.some(function(mode){return mode.id===selected;}))select.val(selected);
    var message=supported.length+' CPU levels validated locally. Cached '+value.local.observedAt+'.';
    if(!value.local.available)message=value.local.error||'Local CPU compatibility is unavailable.';
    else if(!value.local.complete)message+=' Some levels remain unconfirmed in this cached observation.';
    if(value.local.referenceDifferences)message+=' Definitions that differ between automatic Q35/i440fx checks remain unconfirmed.';
    if(value.local.available&&!showAll)message+=' Showing one preferred revision for each of '+visible.length+' CPU families.';
    $('#unm-cpu-status').text(message);
    var matrix=$('#unm-cpu-matrix').empty();
    if(value.local.available){
      var table=$('<table>').addClass('unm-table'),head=$('<tr>').append($('<th>').text('CPU level')).append($('<th>').text('Local'));
      (value.peers||[]).forEach(function(peer){head.append($('<th>').text(peer.name));});
      table.append($('<thead>').append(head));var body=$('<tbody>');
      visible.forEach(function(mode){
        var row=$('<tr>').append($('<td>').text(mode.id+(suggestion.id===mode.id?' — suggested':''))).append($('<td>').text('Supported'));
        (value.peers||[]).forEach(function(peer){
          var level=(peer.levels||[]).find(function(item){return item.id===mode.id;});
          row.append($('<td>').text(level&&level.common===true?'Common':level&&level.common===false?'Not common':'Unconfirmed').prop('title',level?level.reason:(peer.error||'Waiting for cached peer results.')));
        });
        body.append(row);
      });table.append(body).appendTo(matrix);
      (value.peers||[]).forEach(function(peer){if(peer.error)$('<p>').addClass('unm-muted').text(peer.name+': '+peer.error).appendTo(matrix);});
    }
    renderCpuLevel();
  }
  function loadPeers(ticket) {
    var selected=$('#unm-cpu-peer').val(),targets=peers.filter(function(peer){return selected==='*'||selected===peer.id;});
    report.scopeReady=selected===''||peerListReady&&(selected==='*'||targets.length===1);
    report.scopeError=report.scopeReady?'':'Paired hosts are unavailable. Select Local host only to inspect a local suggestion, or reload the page.';
    report.peers=targets.map(function(peer){return {id:peer.id,name:peer.name||peer.host,levels:[],error:'',complete:false};});showCpuCompatibility(report);
    if(!report.scopeReady)return;
    var pending=targets.slice(),active=0;
    function next(){
      if(ticket!==serial)return;
      while(active<3&&pending.length){
        active++;
        (function(peer){
          function finish(value,error){
            var item=report.peers.find(function(item){return item.id===peer.id;});
            if(value&&value.success&&(!value.local.cache||value.local.cache.identity!==report.local.cache.identity)){
              value=null;error='Local CPU cache changed. Read results again.';
            }
            item.levels=value&&value.success?(value.levels||[]):[];
            item.report=value&&value.success?value.peer:null;
            item.error=error||(value&&(value.peerError||(!value.success&&value.error)))||(value&&value.peer&&!value.peer.available&&value.peer.error)||'';
            item.complete=true;
            active--;showCpuCompatibility(report);next();
          }
          post({action:'cpuCompatibilityPeer',peer_id:peer.id},ticket,function(value){finish(value,'');},function(){finish(null,'Peer cache could not be read. No compatibility is assumed.');});
        })(pending.shift());
      }
    }
    next();
  }
  function checkCpuCompatibility() {
    clearCpuCompatibility();var ticket=serial,attempt=0;
    $('#unm-cpu-check').prop('disabled',true).val('Reading cache…');
    function read(){
      post({action:'cpuCompatibility'},ticket,function(value){
        if(!value.success){$('#unm-cpu-status').text(value.error||'CPU cache could not be read.');$('#unm-cpu-check').prop('disabled',false).val('Read cached results');return;}
        if(Array.isArray(value.pairedPeers)){setCpuPeers(value.pairedPeers);peerSnapshotFromCpu=true;}
        value.peers=[];value.scopeReady=false;showCpuCompatibility(value);
        if(!value.local.available&&value.local.cache&&value.local.cache.refreshing&&++attempt<360){timer=setTimeout(read,2000);return;}
        $('#unm-cpu-check').prop('disabled',false).val('Read cached results');
        if(value.local.available)loadPeers(ticket);
      },function(error){$('#unm-cpu-status').text((error&&error.error)||'CPU cache could not be read. Check connectivity and try again.');$('#unm-cpu-check').prop('disabled',false).val('Read cached results');});
    }
    $('#unm-cpu-status').text('Reading cached CPU results; any first-time collection runs in the background…');read();
  }
  $(function(){
    if(!$('#unm-cpu-compatibility').length)return;
    $('#unm-cpu-check').on('click',checkCpuCompatibility);
    $('#unm-cpu-level').on('change',function(){levelChosen=true;renderCpuLevel();});
    $('#unm-cpu-all-revisions').on('change',function(){if(report)showCpuCompatibility(report,false);});
    $('#unm-cpu-peer').on('change',clearCpuCompatibility);
    $.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:30000,data:{action:'peers'}}).done(function(value){
      if(peerSnapshotFromCpu||!value.success&&value.success!==undefined)return;
      setCpuPeers(value.peers||[]);
      if(report&&report.local.available)loadPeers(serial);
    }).fail(function(){$('#unm-cpu-status').text('Paired hosts could not be loaded. Local CPU results are still available.');});
    $.ajax({url:'/plugins/unmotion/api.php',method:'POST',dataType:'json',timeout:15000,data:{action:'cpuCompatibilityScope'}}).done(showCpuScope).fail(function(){showCpuScope({success:false});});
  });
})(jQuery);
