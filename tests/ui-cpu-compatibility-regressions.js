// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/cpu-compatibility.js','utf8');
const elements=new Map(),requests=[],timers=new Map();let ready,clockId=0;
class Element {
  constructor(){this.value='';this.content='';this.children=[];this.props={};this.handlers={};this.length=1;}
  empty(){this.children=[];this.content='';this.value='';return this;}
  prop(k,v){if(v===undefined)return this.props[k];this.props[k]=v;return this;}
  val(v){if(v===undefined)return this.value;this.value=v;return this;}
  text(v){if(v===undefined)return this.content;this.content=String(v);return this;}
  append(child){this.children.push(child);return this;}
  appendTo(target){const parent=typeof target==='string'?$(target):target;parent.children.push(this);if(parent.value==='')parent.value=this.value;return this;}
  addClass(){return this;}
  on(name,fn){this.handlers[name]=fn;return this;}
  toggle(visible){this.props.visible=visible;return this;}
}
function $(key){if(typeof key==='function'){ready=key;return;}if(key.startsWith('<'))return new Element();if(!elements.has(key))elements.set(key,new Element());return elements.get(key);}
$.ajax=options=>{const h={};const r={options,aborted:false,done(fn){h.done=fn;return this;},fail(fn){h.fail=fn;return this;},always(fn){h.always=fn;return this;},abort(){this.aborted=true;if(h.fail)h.fail({statusText:'abort'});if(h.always)h.always();},success(v){h.done(v);if(h.always)h.always();},failure(error){h.fail({statusText:'error',responseJSON:error});if(h.always)h.always();}};requests.push(r);return r;};
const context={jQuery:$,setTimeout(fn){timers.set(++clockId,fn);return clockId;},clearTimeout(id){timers.delete(id);}};vm.createContext(context);
vm.runInContext(source.replace('})(jQuery);','globalThis.cpuTest={clearCpuCompatibility,checkCpuCompatibility,showCpuCompatibility,renderCpuLevel,sortedCpuModes,cpuSuggestion,cpuFamilyModes,cpuVersionGuidance,showCpuScope};})(jQuery);'),context);
const ui=context.cpuTest;
$('#unm-cpu-peer').val('*');ready();
const peerListRequest=requests.find(r=>r.options.data.action==='peers');
const scopeRequest=requests.find(r=>r.options.data.action==='cpuCompatibilityScope');
scopeRequest.success({success:true,complete:true,error:'',unsupportedVms:[{name:'<b>Squid Proxy</b>',machine:'microvm',reason:'Outside Q35/i440fx scope.'}]});
assert.match($('#unm-cpu-scope-results').children[0].content,/<b>Squid Proxy<\/b>.*Existing cold\/warm moves are unchanged/,'Unsupported VM metadata is escaped text and does not imply existing moves are blocked.');
assert.doesNotMatch(source,/unm-cpu-machine|cpuCompatibilityMachines|machineValue/,'No machine selector or hidden user-machine choice remains in the UI.');
peerListRequest.success({success:true,peers:[{id:'peer-one',name:'First peer',host:'one',pairingState:'paired'},{id:'peer-two',name:'<b>Second peer</b>',host:'two',pairingState:'paired'},{id:'pending',name:'Incomplete',host:'three',pairingState:'pending'}]});
assert.equal($('#unm-cpu-peer').children.length,4,'Only fully paired hosts plus all/local choices are listed.');
$('#unm-cpu-peer').val('peer-one');
ui.checkCpuCompatibility();const first=requests.at(-1);
assert.equal(first.options.method,'POST');assert.equal(first.options.data.action,'cpuCompatibility');
assert.deepEqual(Object.keys(first.options.data),['action'],'The normal API request selects automatic reference contexts, not a user machine type.');
assert.equal($('#unm-cpu-check').props.disabled,true);
$('#unm-cpu-peer').val('peer-two');ui.clearCpuCompatibility();assert.equal(first.aborted,true);
const report={success:true,local:{overviewVersion:1,available:true,complete:true,hostname:'<img src=x onerror=bad()>',observedAt:'now',cache:{identity:'cpu-one',cached:true,status:'ready'},context:{machine:'reference-q35-i440fx',vendor:'Intel'},modes:[{id:'Skylake-Server-v3',cpuXml:'<cpu/>',status:'supported'}]},peer:{available:true,hostname:'peer',observedAt:'now'},levels:[{id:'Skylake-Server-v3',localStatus:'supported',peerStatus:'supported',common:true,reason:'Same definition'}]};
first.success(report);assert.equal($('#unm-cpu-level').children.length,0,'Late results cannot populate another peer selection.');
ui.checkCpuCompatibility();requests.at(-1).success(report);
assert.equal(requests.at(-1).options.data.action,'cpuCompatibilityPeer','Peer comparisons are a separate cached-only operation.');assert.equal('machine' in requests.at(-1).options.data,false,'Peer requests do not leak a reference marker as a machine selector.');requests.at(-1).success(report);
assert.equal($('#unm-cpu-level').children.length,1);assert.match($('#unm-cpu-level').children[0].content,/common with/);
assert.match($('#unm-cpu-status').content,/1 CPU levels validated locally. Cached/);assert.equal($('#unm-cpu-check').props.disabled,false);
assert.match($('#unm-cpu-result').children[0].content,/<img src=x/,'Host strings use text(), never HTML.');
assert.match($('#unm-cpu-result').children[0].content,/Q35\/i440fx host overview/,'The result is labelled as a host overview, not VM-specific migration approval.');
ui.checkCpuCompatibility();requests.at(-1).failure();assert.equal($('#unm-cpu-level').children.length,0,'Failed reads cannot retain a previously common level.');assert.match($('#unm-cpu-status').content,/could not be read/);
ui.checkCpuCompatibility();requests.at(-1).failure({error:'Cached reference data is invalid.'});assert.equal($('#unm-cpu-status').content,'Cached reference data is invalid.','Validation errors are not disguised as connection failures.');
ui.showCpuCompatibility({...report,peers:[{name:'Older peer',error:'Older peer has no cached CPU discovery',levels:[]}]});
assert.doesNotMatch($('#unm-cpu-level').children[0].content,/common with/);assert.equal($('#unm-cpu-matrix').children.at(-1).content,'Older peer: Older peer has no cached CPU discovery');
ui.checkCpuCompatibility();requests.at(-1).success({...report,local:{...report.local,available:false,cache:{status:'collecting',refreshing:true},error:'Background collection'}});
assert.equal(timers.size,1,'Pending collection polls cached status.');assert.equal($('#unm-cpu-check').props.disabled,true);
ui.clearCpuCompatibility();assert.equal(timers.size,0,'Context changes cancel polling.');
$('#unm-cpu-peer').val('*');ui.checkCpuCompatibility();requests.at(-1).success(report);
let peerRequests=requests.filter(r=>r.options.data.action==='cpuCompatibilityPeer').slice(-2);
assert.equal(peerRequests.length,2);peerRequests[0].success(report);peerRequests[1].failure();
assert.match($('#unm-cpu-level').children[0].content,/common with First peer/);assert.doesNotMatch($('#unm-cpu-level').children[0].content,/common with.*Second peer/,'Offline peers cannot inherit success.');
ui.checkCpuCompatibility();requests.at(-1).success(report);peerRequests=requests.filter(r=>r.options.data.action==='cpuCompatibilityPeer').slice(-2);
peerRequests[0].success({...report,local:{...report.local,cache:{identity:'cpu-two'}}});peerRequests[1].failure();
assert.doesNotMatch($('#unm-cpu-level').children[0].content,/common with/,'CPU changes cannot mix old masks with new peer comparisons.');
const mode=(id,deprecated=false,status='supported')=>({id,deprecated,status});
const local={...report.local,modes:[mode('kvm64-v1'),mode('Skylake-Server-v5'),mode('Cascadelake-Server-v3'),mode('Broadwell-v4'),mode('Cascadelake-Server-v5'),mode('Cascadelake-Server-v10'),mode('FutureChip-v99'),mode('Opteron_G5-v1'),mode('Icelake-Server-v6',true),mode('SapphireRapids-v1',false,'unknown')]};
const sorted=ui.sortedCpuModes(local);
assert.deepEqual(Array.from(sorted.slice(0,4),m=>m.id),['Cascadelake-Server-v10','Cascadelake-Server-v5','Cascadelake-Server-v3','Skylake-Server-v5'],'Newer families and numeric revisions precede older levels.');
assert.equal(sorted.at(-1).id,'Icelake-Server-v6','Deprecated levels stay available but below non-deprecated ones.');
assert.equal(sorted.some(m=>m.id==='SapphireRapids-v1'),false,'Unvalidated levels cannot enter the list.');
const amd={...local,context:{vendor:'AMD'},modes:[mode('EPYC-v4'),mode('EPYC-Milan-v2'),mode('EPYC-Rome-v4'),mode('EPYC-Genoa-v1'),mode('Skylake-Server-v5')]};
assert.equal(ui.sortedCpuModes(amd)[0].id,'EPYC-Genoa-v1','AMD models use their own generation ordering.');
const oldLevels={...local,modes:['486-v1','pentium-v1','kvm32-v1','pentium2-v1','qemu64-v1','pentium3-v1','kvm64-v1','qemu32-v1'].map(id=>mode(id))};
assert.deepEqual(Array.from(ui.sortedCpuModes(oldLevels),m=>m.id),['pentium3-v1','pentium2-v1','pentium-v1','486-v1','kvm64-v1','qemu64-v1','kvm32-v1','qemu32-v1'],'Legacy generations use historical order; synthetic KVM/QEMU baselines are a separate group below named CPUs.');
const levels=(ids)=>ids.map(id=>({id,common:true,reason:'Same definition'}));
const comparison={local,scopeReady:true,peers:[{name:'One',complete:true,levels:levels(['Cascadelake-Server-v5','Skylake-Server-v5'])},{name:'Two',complete:true,levels:levels(['Skylake-Server-v5'])}]};
const commonRevision={local,scopeReady:true,peers:[{name:'One',complete:true,levels:levels(['Cascadelake-Server-v3','Cascadelake-Server-v5','Skylake-Server-v5'])},{name:'Two',complete:true,levels:levels(['Cascadelake-Server-v3','Skylake-Server-v5'])}]};
assert.equal(ui.cpuFamilyModes(commonRevision,sorted).find(m=>m.id.startsWith('Cascadelake')).id,'Cascadelake-Server-v3','Collapsed view uses the highest exact revision shared by every peer, not the highest local revision.');
assert.equal(ui.cpuFamilyModes(comparison,sorted).find(m=>m.id.startsWith('Cascadelake')).id,'Cascadelake-Server-v10','A family with no common revision stays visible as local-only/unconfirmed, not hidden.');
assert.equal(ui.cpuFamilyModes({...commonRevision,peers:[commonRevision.peers[0],{complete:false,levels:[]}]},sorted).find(m=>m.id.startsWith('Cascadelake')).id,'Cascadelake-Server-v10','Pending peers cannot produce a subset-based collapsed match.');
const ids=Array.from(ui.cpuFamilyModes(commonRevision,sorted),m=>m.id.replace(/-v[0-9]+$/,''));
assert.equal(new Set(ids).size,ids.length,'Normal view has exactly one row per family.');
assert.equal(ui.cpuSuggestion(comparison,sorted).id,'Skylake-Server-v5','The suggestion intersects every selected peer, not a majority or the newest local level.');
assert.equal(ui.cpuSuggestion({...comparison,scopeReady:false},sorted).id,undefined,'An unavailable pairing list cannot imply a local-only suggestion.');
assert.equal(ui.cpuSuggestion({...comparison,peers:[comparison.peers[0],{complete:false,levels:[]}]},sorted).id,undefined,'No partial recommendation while another peer is pending.');
assert.equal(ui.cpuSuggestion({...comparison,peers:[comparison.peers[0],{complete:true,error:'offline',levels:[]}]},sorted).id,undefined,'Offline peers cannot be ignored by the recommendation.');
assert.equal(ui.cpuSuggestion({...comparison,peers:[{complete:true,levels:[{id:'Cascadelake-Server-v10',common:null}]}]},sorted).id,undefined,'Unknown is not common.');
assert.equal(ui.cpuSuggestion({...comparison,peers:[]},sorted).id,'Cascadelake-Server-v10','Local-only mode suggests a validated native-family level.');
assert.equal(ui.cpuSuggestion({local:{...local,modes:[mode('FutureChip-v99')]},scopeReady:true,peers:[]},[mode('FutureChip-v99')]).id,undefined,'Unknown families remain visible but cannot be guessed into a recommendation.');
assert.equal(ui.cpuSuggestion({local:{...local,context:{vendor:'constructor'}},scopeReady:true,peers:[]},sorted).id,undefined,'Unknown CPU vendor is not a native-family match.');
ui.clearCpuCompatibility();$('#unm-cpu-all-revisions').prop('checked',false);ui.showCpuCompatibility(commonRevision);
assert.equal($('#unm-cpu-level').children.filter(m=>m.value.startsWith('Cascadelake')).length,1);
assert.equal($('#unm-cpu-level').val(),'Cascadelake-Server-v3');
assert.match($('#unm-cpu-status').content,/Showing one preferred revision/);
const requestCount=requests.length;
$('#unm-cpu-all-revisions').prop('checked',true);$('#unm-cpu-all-revisions').handlers.change();
assert.equal($('#unm-cpu-level').children.filter(m=>m.value.startsWith('Cascadelake')).length,3,'Advanced view restores all validated revisions.');
assert.equal(requests.length,requestCount,'Revision toggle is a local cache render; it does not scan or contact peers.');
$('#unm-cpu-level').val('Cascadelake-Server-v5');$('#unm-cpu-level').handlers.change();
$('#unm-cpu-all-revisions').prop('checked',false);$('#unm-cpu-all-revisions').handlers.change();
assert.equal($('#unm-cpu-level').val(),'Cascadelake-Server-v3','Explicitly collapsing replaces an inspected revision with the representative in the same family.');
ui.clearCpuCompatibility();$('#unm-cpu-all-revisions').prop('checked',false);ui.showCpuCompatibility({...commonRevision,scopeReady:false,peers:[]});
$('#unm-cpu-level').val('Cascadelake-Server-v10');$('#unm-cpu-level').handlers.change();ui.showCpuCompatibility(commonRevision);
assert.equal($('#unm-cpu-all-revisions').prop('checked'),true,'Late peer results expand the advanced view instead of losing a manually inspected revision.');
assert.equal($('#unm-cpu-level').val(),'Cascadelake-Server-v10');assert.match($('#unm-cpu-revision-note').content,/keep the level/);
const softwareContext={architecture:'x86_64',virtType:'kvm',vendor:'Intel',machine:'pc-q35-10.2'};
const versions={local:{...local,context:softwareContext},scopeReady:true,peers:[{complete:true,error:'',levels:levels(['Cascadelake-Server-v3']),report:{...local,context:softwareContext,modes:[mode('Cascadelake-Server-v3')]}}]};
assert.match(ui.cpuVersionGuidance(versions),/Check Unraid updates/,'Different validated revisions suggest checking Unraid updates.');
assert.match(ui.cpuVersionGuidance(versions),/not guaranteed/,'Update guidance does not promise that software caused the difference.');
const equalVersions={...versions,peers:[{...versions.peers[0],report:{...versions.local},levels:levels(['Cascadelake-Server-v10'])}]};
assert.equal(ui.cpuVersionGuidance(equalVersions),'','Equal validated revisions do not show upgrade advice.');
assert.match(ui.cpuVersionGuidance({...equalVersions,peers:[{...equalVersions.peers[0],levels:[{id:'Cascadelake-Server-v10',common:false,peerStatus:'supported'}]}]}),/Check Unraid updates/,'Equal revision names with different exact masks still produce definition guidance.');
assert.equal(ui.cpuVersionGuidance({...versions,peers:[{...versions.peers[0],report:{available:true,context:softwareContext,modes:[null,{status:'supported'},{id:{},status:'supported'}]}}]}),'','Malformed peer modes cannot break the optional guidance render.');
assert.equal(ui.cpuVersionGuidance({...versions,peers:[{...versions.peers[0],report:{...versions.peers[0].report,context:{...softwareContext,vendor:'AMD'}}}]}),'','CPU-vendor mismatch is not presented as an Unraid update problem.');
assert.equal(ui.cpuVersionGuidance({...versions,peers:[{...versions.peers[0],error:'offline'}]}),'','Offline peers do not generate speculative version advice.');
assert.equal(ui.cpuVersionGuidance({...versions,peers:[{complete:true,report:{available:true},levels:[]}]}),'','Malformed peer context cannot crash the guidance render.');
ui.clearCpuCompatibility();assert.equal($('#unm-cpu-revision-note').content,'');assert.equal($('#unm-cpu-version-guidance').content,'');
$('#unm-cpu-all-revisions').prop('checked',false);
ui.showCpuCompatibility(comparison);assert.equal($('#unm-cpu-level').val(),'Skylake-Server-v5','The suggested choice is selected without applying it to a VM.');
$('#unm-cpu-level').val('Broadwell-v4');$('#unm-cpu-level').handlers.change();ui.showCpuCompatibility(comparison);
assert.equal($('#unm-cpu-level').val(),'Broadwell-v4','Asynchronous peer updates preserve a user-inspected level.');
ui.clearCpuCompatibility();assert.equal($('#unm-cpu-suggestion').content,'','Context changes remove obsolete recommendations.');
$('#unm-cpu-peer').val('*');ui.checkCpuCompatibility();requests.at(-1).success({...report,pairedPeers:[{id:'new-pair',name:'New pair',host:'new',pairingState:'paired'}]});
assert.equal(requests.at(-1).options.data.peer_id,'new-pair','Every read uses the current pairing snapshot, not the page-load list.');
requests.at(-1).failure();assert.doesNotMatch($('#unm-cpu-suggestion').content,/Suggested:/,'A newly paired but unavailable host prevents an all-host suggestion.');
$('#unm-cpu-peer').val('new-pair');ui.checkCpuCompatibility();requests.at(-1).success({...report,pairedPeers:[]});
assert.match($('#unm-cpu-suggestion').content,/Paired hosts are unavailable/,'A removed selected peer cannot silently become a local-only recommendation.');
assert.doesNotMatch(source,/\.html\(/,'CPU/host/model content is rendered as text.');
console.log('Automatic CPU overview, family ordering, suggestions, VM scope, cache polling, response isolation and escaping passed.');
