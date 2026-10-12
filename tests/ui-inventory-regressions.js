// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const between=(a,b)=>source.slice(source.indexOf(a),source.indexOf(b,source.indexOf(a)));
let html='',status='',calls=[],callbacks={},timers=[];
function $(selector){return {length:1,empty(){html='';return this;},html(value){html=value;return this;},append(value){html+=value;return this;},val(){return '';},text(value){status=value;return this;}};}
const context={$,document:{hidden:false},inventoryMeta:{available:false,refreshing:true},latestVms:[],inventoryRetryTimer:null,inventoryForcePending:false,pendingPolls:{},clearTimeout(){},setTimeout(fn){timers.push(fn);return 1;},fmtWhen:x=>x,fmtRamMib:x=>String(x),esc:x=>String(x),post(action,data){calls.push({action,data});return {done(fn){callbacks.done=fn;return this;},fail(fn){callbacks.fail=fn;return this;},always(fn){callbacks.always=fn;return this;}};}};
vm.createContext(context);
vm.runInContext(between('function renderInventory()','function disableSeedActions('),context);
vm.runInContext(between('function renderInventoryStatus()','function loadSeedsOnly()'),context);
context.renderInventory();assert.match(html,/Loading VM inventory/);assert.doesNotMatch(html,/No VMs/);
context.loadInventory(false);assert.equal(calls[0].data.refresh,0);
callbacks.done({vms:[{name:'Squid Proxy',uuid:'u',state:'running',vcpus:2,memoryMiB:512,autostart:'disable',detailsPending:true}],inventory:{available:true,complete:false,refreshing:true}});
assert.match(html,/Squid Proxy/);assert.match(html,/Checking storage and guest agent/);assert.match(html,/disabled/);
assert.equal(timers.length,1,'Partial inventory is polled promptly.');
const previous=html;context.loadInventory(false);assert.equal(html,previous,'Refresh never blanks rows.');
callbacks.fail({});assert.match(html,/Squid Proxy/);assert.match(status,/out of date/);
context.latestVms=[];context.inventoryMeta={available:true,complete:true};context.renderInventory();assert.match(html,/No VMs found/,'Only proven empty inventory shows no VMs.');
context.loadInventory();assert.equal(calls.at(-1).data.refresh,1,'Explicit refresh invalidates cached observations.');
context.document.hidden=true;const count=calls.length;context.loadInventory(false);assert.equal(calls.length,count,'Hidden tab does not poll.');
context.document.hidden=false;
context.pendingPolls['inventory:']={};context.loadInventory(true);assert.equal(context.inventoryForcePending,true,'User refresh is retained during an in-flight poll.');
delete context.pendingPolls['inventory:'];callbacks.always();assert.equal(calls.at(-1).data.refresh,1,'Queued refresh runs after the poll.');
// Real request helper reuses an in-flight request per endpoint/peer, not one global mutex.
let requests=[],always=[];
$.ajax=options=>{const request={always(fn){always.push(fn);return this;}};requests.push({options,request});return request;};
context.api='/api';context.pendingPolls={};
vm.runInContext(between('function post(','function setButtonBusy('),context);
const first=context.post('inventory',{});assert.equal(context.post('inventory',{}),first);assert.equal(requests.length,1);
context.post('peerHealth',{peer_id:'one'});context.post('peerHealth',{peer_id:'two'});assert.equal(requests.length,3,'Different peers can load independently.');
context.post('startMigration',{});context.post('startMigration',{});assert.equal(requests.length,5,'Mutation semantics unchanged.');
always[0]();context.post('inventory',{});assert.equal(requests.length,6,'Settled reads can refresh again.');
assert.equal(requests[0].options.timeout,30000,'Polling has bounded requests.');
console.log('Progressive inventory, loading/empty/error states, row preservation, hidden tabs and single-flight polling passed.');
