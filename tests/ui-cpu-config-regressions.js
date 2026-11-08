// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/cpu-config.js','utf8');
const elements=new Map(),requests=[];let ready;
class Element{
  constructor(){this.value='';this.content='';this.children=[];this.props={};this.handlers={};this.length=1;}
  empty(){this.content='';this.children=[];return this;}text(v){this.content=String(v);return this;}
  append(v){this.children.push(v);return this;}appendTo(k){$(k).append(this);return this;}
  val(v){if(v===undefined)return this.value;this.value=v;return this;}prop(k,v){if(v===undefined)return this.props[k];this.props[k]=v;return this;}
  attr(k,v){this.props[k]=v;return this;}show(){this.props.visible=true;return this;}hide(){this.props.visible=false;return this;}
  addClass(k){if(k==='open')this.props.visible=true;return this;}removeClass(k){if(k==='open')this.props.visible=false;return this;}
  on(k,f){this.handlers[k]=f;return this;}trigger(k){if(this.handlers[k])this.handlers[k]();return this;}
}
function $(k){if(typeof k==='function'){ready=k;return;}if(typeof k==='object')return k;if(k.startsWith('<'))return new Element();if(!elements.has(k))elements.set(k,new Element());return elements.get(k);}
$.ajax=options=>{const h={};const r={options,done(f){h.done=f;return this;},fail(f){h.fail=f;return this;},always(f){h.always=f;return this;},abort(){this.aborted=true;},success(v){h.done(v);h.always();},failure(error){h.fail({responseJSON:{error}});h.always();}};requests.push(r);return r;};
const context={jQuery:$,document:{activeElement:{focus(){}}}};vm.createContext(context);vm.runInContext(source,context);ready();
function selected(mode='Mode-v2'){return JSON.stringify({mode,definition_hash:'a'.repeat(64),cache_identity:'b'.repeat(64)});}
function click(id){$('#'+id).handlers.click();}
const result={success:true,review:'c'.repeat(64),eligible:1,mode:'Mode-v2',vms:[{name:'Squid Proxy <script>',status:'eligible'},{name:'Other VM',status:'skipped',reason:'Saved memory state'}]};
$('#unm-cpu-selection').val(selected());click('unm-cpu-apply');
assert.equal($('#unm-cpu-config-modal').props.visible,true,'Review appears immediately before network response.');
assert.match($('#unm-cpu-config-content').children[0].content,/Checking persistent/);assert.equal($('#unm-cpu-config-confirm').props.disabled,true);
assert.equal(requests[0].options.method,'POST');assert.equal(requests[0].options.data.action,'cpuConfigPreview');
click('unm-cpu-config-cancel');requests[0].success(result);assert.equal($('#unm-cpu-config-modal').props.visible,false,'Late cancelled preview stays closed.');
click('unm-cpu-apply');requests.at(-1).success(result);
assert.equal($('#unm-cpu-config-confirm').props.disabled,false);assert.match($('#unm-cpu-config-content').children[2].content,/<script>/,'VM names are rendered as text.');
$('#unm-cpu-selection').val(selected('Different-v3'));click('unm-cpu-config-confirm');assert.equal(requests.length,2,'A changed selection cannot apply an old review.');
click('unm-cpu-config-cancel');click('unm-cpu-apply');requests.at(-1).success(result);click('unm-cpu-config-confirm');
assert.equal(requests.at(-1).options.data.action,'cpuConfigApply');assert.equal(requests.at(-1).options.data.review,result.review);
assert.equal($('#unm-cpu-config-cancel').props.disabled,true);click('unm-cpu-config-confirm');assert.equal(requests.length,4,'Double apply is ignored.');
click('unm-cpu-config-cancel');assert.equal($('#unm-cpu-config-modal').props.visible,true,'In-flight apply cannot be dismissed/aborted.');
requests.at(-1).success({...result,applied:1,complete:false,backupDirectory:'/boot/config/plugins/unmotion/cpu-config-backups/batch-test'});
assert.match($('#unm-cpu-config-content').children[0].content,/batch stopped/);assert.match($('#unm-cpu-config-content').children[1].content,/batch-test/);
assert.equal($('#unm-cpu-config-cancel').props.disabled,false);assert.equal($('#unm-cpu-config-confirm').props.visible,false);
click('unm-cpu-config-cancel');click('unm-cpu-apply');requests.at(-1).failure('No schema available');assert.equal($('#unm-cpu-config-content').children[0].content,'No schema available');
click('unm-cpu-config-cancel');click('unm-cpu-apply');requests.at(-1).success(result);click('unm-cpu-config-confirm');requests.at(-1).failure();
assert.match($('#unm-cpu-config-content').children[0].content,/some XMLs may have been updated/,'Lost apply response must not claim no changes.');
assert.doesNotMatch(source,/\b(?:confirm|alert)\(/,'Uses a styled review, not native popups.');
console.log('CPU bulk review, cancellation, stale selection, escaped names, double submission and uncertain result UI passed.');
