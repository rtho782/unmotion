// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const between=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
let confirmations=[],posts=[],flashes=[],handlers={},values={'#unm-peer-select':'peer-2','#unm-clone-name':'Squid Proxy Clone','#unm-clone-customization':'none-disconnected'};
const chain=value=>({done(fn){fn(value);return this;},fail(){return this;},always(fn){fn();return this;},promise(){return this;}});
function $(arg){if(arg&&typeof arg==='object'&&arg.attr)return arg;return {val:()=>values[arg],on:(event,selector,handler)=>{handlers[selector]=handler;},removeClass(){},attr(){return '';}};}
$.Deferred=()=>({resolve:value=>chain(value)});
const context={$,document:{},activePreflight:{vm:{tpm:true,usb:[{}]},peerHealth:{issues:[{message:'Routine scrub'}]}},activeWarmSeedId:'seed-1',requestConfirmation:opts=>{confirmations.push(opts);return chain(false);},setButtonBusy:()=>()=>{},post:(action,data)=>{posts.push({action,data});return chain(action==='preflight'?{preflight:{ready:true,notes:['TPM is transferred at cutover']}}:{job:{id:'test'},seed:{recordOnly:true}});},flash:(...args)=>flashes.push(args),loadInventory(){},loadSeedsOnly(){},loadJobs(){},err(){},renderPreparationMessages(){},activeVmId:'vm-1',seeds:[{id:'seed-1',vmUuid:'vm-1',peerId:'peer-1'}],recoveryMutation:(...args)=>posts.push(args)};
vm.createContext(context);
vm.runInContext(between('  function confirmRiskyMigration(', '  function startMigration('),context);
context.confirmRiskyMigration({source_cleanup_action:'retain',destination_conflict_action:'cancel'});
assert.equal(confirmations.length,0,'TPM/USB/health do not trigger extra confirmation');
context.confirmRiskyMigration({source_cleanup_action:'delete',destination_conflict_action:'cancel'});
context.confirmRiskyMigration({source_cleanup_action:'retain',destination_conflict_action:'overwrite'});
assert.equal(confirmations.length,2);assert.ok(confirmations.every(x=>x.tone==='danger'&&x.title==='Confirm data deletion'));
confirmations=[];
vm.runInContext(between('function startWarmOperation(', 'function renderPreparationMessages('),context);
context.startWarmOperation('vm-1','prepare',{attr:()=>''});
assert.equal(posts[0].action,'preflight');assert.equal(posts[1].action,'prepareWarm');assert.equal(confirmations.length,0);
posts=[];context.startWarmOperation('vm-1','update',{attr:()=> 'seed-1'});
assert.equal(JSON.parse(posts[0].data.options).migration_mode,'warm-update');assert.equal(posts[0].data.peer_id,'peer-1');assert.equal(posts[1].action,'updateWarm');
posts=[];context.post=(action,data)=>{posts.push({action,data});return chain({preflight:{ready:false,errors:['Pool fault']}});};
context.startWarmOperation('vm-1','prepare',{attr:()=>''});assert.equal(posts.length,1,'blocked preflight never starts a worker');
context.post=(action,data)=>{posts.push({action,data});return chain({job:{id:'test'},seed:{recordOnly:true}});};
vm.runInContext(between('  function startClone()', 'function isCancellable('),context);
posts=[];context.startClone();assert.equal(posts[0].action,'startClone');assert.equal(confirmations.length,0);
// Extract the complete handler up to the next registration (the first match is itself).
const start=source.indexOf("    $(document).on('click','.unm-remove-seed'");
const end=source.indexOf("    $(document).on(",start+10);
vm.runInContext(source.slice(start,end),context);
posts=[];handlers['.unm-remove-seed'].call({attr:key=>key==='data-id'?'seed-1':'1'});
assert.equal(confirmations.length,0);assert.equal(posts[0].data.record_only,1,'archive request cannot fall through to deletion');
posts=[];handlers['.unm-remove-seed'].call({attr:key=>key==='data-id'?'seed-1':'0'});
assert.equal(confirmations.at(-1).title,'Delete prepared storage?');assert.equal(posts.length,0,'cancelled deletion has no mutation');
for(const text of ['Confirm TPM-aware preparation','Confirm risky migration','Retry hold acknowledgement?','Renew coordinated claim?','Check cold failback prerequisites?'])assert.ok(!source.includes(text),text);
assert.ok(source.includes('Replication only:')&&source.includes('Recovery is more reliable without a TPM.'),'replication consistency warnings retained');
const isoLine=source.split('\n').find(line=>line.includes('<label>Optical media</label>'));
vm.runInContext(between('function isCancellable(', 'function loadJobs('),context);
assert.equal(context.isCancellable('WAITING_FOR_MIGRATION'),true,'waiting migration remains cancellable');
context.isoHtml=undefined;vm.runInContext('function isoHtml(v){var html="",iso="copy";'+isoLine+';return html;}',context);
assert.equal(context.isoHtml({isos:[]}), '');assert.ok(context.isoHtml({isos:['/mnt/isos/boot.iso']}).includes('Copy attached ISOs'));
assert.ok(source.includes('if(seed.activeJob)actions=disableSeedActions')&&source.includes('if(seed&&seed.activeJob)actions=disableSeedActions'),'both seed and inventory actions respect busy state');
console.log('UI confirmation, TPM, destructive-action, storage-only preflight, archive-only and ISO visibility regressions passed.');
