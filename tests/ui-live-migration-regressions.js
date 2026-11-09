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
    empty(){this.content='';return this;},append(v){this.content=v.content;return this;}});
  return elements.get(key);
}
$.extend=Object.assign;
$.ajax=function(options){const r={options,readyState:1,done(f){this.success=f;return this;},fail(f){this.failure=f;return this;},always(f){this.finished=f;return this;},
  abort(){this.aborted=true;this.readyState=4;if(this.failure)this.failure({},'abort');if(this.finished)this.finished();},
  resolve(v){this.readyState=4;if(this.success)this.success(v);if(this.finished)this.finished();},reject(v){this.readyState=4;if(this.failure)this.failure(v,'error');if(this.finished)this.finished();}};requests.push(r);return r;};
const context={jQuery:$,document:{hidden:false},window:{getSelection(){return null;}}};vm.createContext(context);
vm.runInContext(source.replace('  $(function(){','  globalThis.testLive={preview,close,readLog,refreshLog,esc};\n  $(function(){'),context);
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
console.log('Live UI immediate dialog, disabled submission, POST, errors, escaping, cancellation and late response isolation passed.');
