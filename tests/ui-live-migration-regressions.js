// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/live-migration.js','utf8');
const elements=new Map(),requests=[];
function $(key){
  if(typeof key==='function')return;
  if(typeof key==='object'&&key.attrs)return key;
  if(!elements.has(key))elements.set(key,{length:1,attrs:{},props:{},value:'',content:'',classes:new Set(),
    attr(k,v){if(v===undefined)return this.attrs[k];this.attrs[k]=v;return this;},
    prop(k,v){if(v===undefined)return this.props[k];this.props[k]=v;return this;},
    val(v){if(v===undefined)return this.value;this.value=v;return this;},
    text(v){if(v===undefined)return this.content;this.content=v;return this;},
    html(v){if(v===undefined)return this.content;this.content=v;return this;},
    show(){this.hidden=false;return this;},hide(){this.hidden=true;return this;},
    addClass(v){this.classes.add(v);return this;},removeClass(v){this.classes.delete(v);return this;},
    empty(){this.content='';return this;},append(v){this.content+=typeof v==='string'?v:v.content;return this;}});
  return elements.get(key);
}
$.extend=Object.assign;
$.ajax=function(options){const r={options,readyState:1,done(f){this.success=f;return this;},fail(f){this.failure=f;return this;},always(f){this.finished=f;return this;},
  abort(){this.aborted=true;this.readyState=4;if(this.failure)this.failure({},'abort');if(this.finished)this.finished();},
  resolve(v){this.readyState=4;if(this.success)this.success(v);if(this.finished)this.finished();},reject(v){this.readyState=4;if(this.failure)this.failure(v,'error');if(this.finished)this.finished();}};requests.push(r);return r;};
const context={jQuery:$,document:{hidden:false},window:{getSelection(){return null;}}};vm.createContext(context);
vm.runInContext(source.replace('  $(function(){','  globalThis.testLive={preview,close,readLog,refreshLog,reviewHandback,reviewCleanup,submitCleanup,esc,capacityHtml,transferText};\n  $(function(){'),context);
const api=context.testLive,button={attrs:{'data-vm-id':'vm1','data-vm-name':'Squid Proxy'},attr(k){return this.attrs[k];}};
$('#unm-peer-select').val('dev2');api.preview(button);
assert.match($('#unm-live-content').content,/Checking the running CPU/);assert.equal($('#unm-live-start').props.disabled,true);assert.equal(requests.length,1);
assert.equal(requests[0].options.method,'POST');assert.equal(requests[0].options.data.action,'livePreview');
api.close();assert.equal(requests[0].aborted,true);requests[0].resolve({success:true,preview:{warnings:[]}});assert.equal($('#unm-live-start').props.disabled,true);
api.preview(button);requests[1].resolve({success:true,preview:{vcpus:2,memoryMiB:2048,diskGiB:12,format:'qcow2',machine:'pc-q35-9.2',warnings:['<img src=x onerror=alert(1)>']}});
assert.equal($('#unm-live-start').props.disabled,false);assert.match($('#unm-live-content').content,/&lt;img/);assert.doesNotMatch($('#unm-live-content').content,/<img/);
api.preview(button);requests[2].reject({responseJSON:{error:'Destination CPU incompatible'}});assert.match($('#unm-live-content').content,/Destination CPU incompatible/);assert.equal($('#unm-live-start').props.disabled,true);
api.readLog('live-a');assert.equal($('#unm-live-start').hidden,true);api.close();requests[3].resolve({success:true,log:'late log'});assert.notEqual($('#unm-live-content').content,'late log');
assert.equal(api.esc('"\'<>&'),'&quot;&#39;&lt;&gt;&amp;');
assert.doesNotMatch(source,/\b(?:alert|confirm|prompt)\s*\(/);assert.match(source,/r\.log===logText/);assert.match(source,/workerActive\?'disabled/);assert.match(source,/selection\.toString\(\)/);
api.reviewHandback('live-b');assert.equal(requests.at(-1).options.data.action,'liveHandbackPreview');assert.equal(requests.at(-1).options.method,'POST');assert.equal($('#unm-live-start').props.disabled,true);
api.close();requests.at(-1).resolve({success:true,review:'late',vmName:'Late VM'});assert.equal($('#unm-live-start').props.disabled,true,'A late hand-back review cannot re-enable a closed dialog.');
api.reviewHandback('live-b');requests.at(-1).resolve({success:true,review:'a'.repeat(64),vmName:'Squid Proxy <script>'});assert.equal($('#unm-live-start').props.disabled,false);assert.match($('#unm-live-content').content,/replication-only/);assert.match($('#unm-live-content').content,/Recovery arming remains unavailable/);assert.match($('#unm-live-content').content,/old copy stays fenced/);
const nextReadiness=requests.length;api.preview(button);requests[nextReadiness].resolve({success:true,ready:false,blockers:[{message:'CPU not active'},{message:'<TPM incompatible>'}],alternative:'Cold/Warm Move has separate checks.',observedAt:'2026-11-10T00:00:00Z'});
assert.equal($('#unm-live-start').props.disabled,true);assert.match($('#unm-live-content').content,/CPU not active/);assert.match($('#unm-live-content').content,/&lt;TPM incompatible&gt;/);assert.match($('#unm-live-content').content,/Recheck readiness/);
assert.match(api.capacityHtml({capacity:{groups:[{group:'<pool>',requiredBytes:1073741824,availableBytes:4294967296,headroomBytes:858993460}]},disks:[{target:'vda',kind:'zvol',sizeGiB:1}]}),/&lt;pool&gt;: 1.00 GiB budget/);
assert.match(api.transferText({phase:'RAM_CONVERGENCE',memory:{remainingBytes:1048576,totalBytes:1073741824},ramIteration:3}),/RAM CONVERGENCE.*RAM:.*RAM pass 3/);
assert.doesNotMatch(api.transferText({phase:'DISK_COPY',disk:{processedBytes:99999,totalBytes:100,remainingBytes:1}}),/%|ETA/);
api.reviewCleanup('live-cleanup');let cleanupRequest=requests.at(-1);assert.equal(cleanupRequest.options.data.action,'liveCleanupPreview');assert.equal(cleanupRequest.options.method,'POST');assert.equal($('#unm-live-start').props.disabled,true);assert.match($('#unm-live-content').content,/No data has been deleted/);api.close();cleanupRequest.resolve({success:true,items:[{}],review:'late'});assert.equal($('#unm-live-start').props.disabled,true,'closed cleanup review cannot re-enable deletion');
api.reviewCleanup('live-cleanup');requests.at(-1).resolve({success:true,complete:false,vmName:'Squid Proxy <script>',review:'r'.repeat(64),objects:[{kind:'zvol',path:'/dev/zvol/cache/<img>'}],items:[{}],allocatedBytes:1073741824,retained:[{kind:'tpm',path:null,reason:'Unknown <state>'}]});assert.equal($('#unm-live-start').props.disabled,false);assert.match($('#unm-live-content').content,/&lt;script&gt;/);assert.match($('#unm-live-content').content,/&lt;img&gt;/);assert.match($('#unm-live-content').content,/Protected artifacts will remain/);assert.match($('#unm-live-content').content,/Actual freed space can differ/);assert.doesNotMatch($('#unm-live-content').content,/<script|<img/);
assert.equal(api.submitCleanup($('#unm-live-start')),true);cleanupRequest=requests.at(-1);assert.equal(cleanupRequest.options.data.action,'liveCleanupStart');assert.equal(cleanupRequest.options.data.confirmation,'delete-inactive-copy');assert.equal(cleanupRequest.options.data.review,'r'.repeat(64));const submitted=requests.length;assert.equal(api.submitCleanup($('#unm-live-start')),false);assert.equal(requests.length,submitted,'double click does not duplicate cleanup');api.close();assert.equal($('#unm-live-modal').classes.has('open'),true,'in-flight submit retains its modal');cleanupRequest.reject({responseJSON:{error:'Uncertain reply'}});assert.match($('#unm-live-content').content,/review it again before retrying/);assert.equal(api.submitCleanup($('#unm-live-start')),false,'uncertain result requires fresh review');
api.reviewCleanup('live-protected');requests.at(-1).resolve({success:true,complete:false,vmName:'Protected',objects:[],items:[],retained:[{kind:'image',path:'/mnt/cache/old.raw',reason:'No admitted identity'}]});assert.equal($('#unm-live-start').props.disabled,true);assert.match($('#unm-live-content').content,/No proven data is eligible/);api.reviewCleanup('live-done');requests.at(-1).resolve({success:true,complete:true,vmName:'Squid Proxy',objects:[],retained:[],cleanup:{message:'Exact cleanup completed'}});assert.equal($('#unm-live-start').hidden,true);assert.match($('#unm-live-content').content,/Cleanup receipt/);assert.equal(api.submitCleanup($('#unm-live-start')),false,'receipt has no deletion grant');
console.log('Live/hand-back/cleanup UI review, POST, exact confirmation, double-submit, errors, escaping, cancellation, receipts and late-response isolation passed.');
