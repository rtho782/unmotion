// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/replication-retirement.js','utf8');
const elements=new Map(),handlers=new Map(),requests=[],events=[];
function $(key){
  if(key&&key.attrs)return key;
  if(!elements.has(key))elements.set(key,{attrs:{},props:{},content:'',value:'',
    attr(k,v){if(v===undefined)return this.attrs[k];this.attrs[k]=v;return this;},prop(k,v){if(v===undefined)return this.props[k];this.props[k]=v;return this;},
    val(v){if(v===undefined)return this.value;this.value=v;return this;},text(v){this.content=v;return this;},html(v){this.content=v;return this;},
    toggle(v){this.visible=v;return this;},addClass(){return this;},removeClass(){return this;},on(type,selector,f){handlers.set(selector,f);return this;},trigger(event){events.push(event);return this;}});
  return elements.get(key);
}
$.extend=Object.assign;$.ajax=function(options){const r={options,done(f){this.success=f;return this;},fail(f){this.failure=f;return this;},always(f){this.finished=f;return this;},abort(){this.aborted=true;if(this.failure)this.failure({statusText:'abort'});},resolve(value){if(this.success)this.success(value);if(this.finished)this.finished();},reject(value){if(this.failure)this.failure(value);if(this.finished)this.finished();}};requests.push(r);return r;};
const context={jQuery:$,document:{}};vm.createContext(context);vm.runInContext(source,context);
const button={attrs:{'data-id':'repl-test'},attr(k){return this.attrs[k];}};
function click(selector,which=button){handlers.get(selector).call(which);}
function review(previous={}){return {success:true,retirement:{id:'repl-test',vmName:'Squid <img>',storage:[{kind:'dataset',source:'pool/Squid Proxy',destination:'backup/Squid <Proxy>'}],review:'review-token',retirement:previous}};}
click('.unm-end-replication');assert.match($('#unm-retire-content').content,/Reading/);assert.equal($('#unm-retire-submit').props.disabled,true);assert.equal(requests[0].options.method,'POST');
click('.unm-retire-close');assert.equal(requests[0].aborted,true);requests[0].resolve(review());assert.equal($('#unm-retire-submit').props.disabled,true,'late review must not reopen controls');
click('.unm-end-replication');requests[1].resolve(review());assert.match($('#unm-retire-content').content,/Squid &lt;img&gt;/);assert.doesNotMatch($('#unm-retire-content').content,/<img>/);assert.equal($('#unm-retire-mode').value,'keep');assert.equal($('#unm-retire-partial').props.checked,false);
$('#unm-retire-mode').val('delete');handlers.get('#unm-retire-mode')();$('#unm-retire-partial').prop('checked',true);click('#unm-retire-submit',$('#unm-retire-submit'));
assert.equal(requests[2].options.data.mode,'delete');assert.equal(requests[2].options.data.discard_partial,'1');assert.equal(requests[2].options.data.review,'review-token');assert.equal(requests[2].options.data.action,'endReplication');
click('#unm-retire-submit',$('#unm-retire-submit'));assert.equal(requests.length,3,'double submission blocked');click('.unm-retire-close');assert.notEqual(requests[2].aborted,true,'do not abort uncertain mutation');
requests[2].reject({responseJSON:{error:'Ownership changed'}});assert.equal($('#unm-retire-error').content,'Ownership changed');
click('.unm-retire-close');click('.unm-end-replication');requests[3].resolve(review({state:'BLOCKED',token:'old',mode:'delete',discardPartial:true}));assert.equal($('#unm-retire-mode').props.disabled,true);assert.equal($('#unm-retire-partial').props.disabled,true);assert.equal($('#unm-retire-submit').value,'Retry retirement');
click('#unm-retire-submit',$('#unm-retire-submit'));requests[4].resolve({retirement:{message:'Ending requested'}});assert.equal($('#unm-retire-content').content,'Ending requested');assert.deepEqual(events,['unmotion:replication-change']);
click('.unm-retire-close');click('.unm-end-replication');requests[5].resolve(review({state:'ENDED_KEEP',token:'old',mode:'keep',discardPartial:false}));assert.equal($('#unm-retire-mode').value,'delete');assert.equal($('#unm-retire-partial').props.checked,false);assert.equal($('#unm-retire-submit').content,'Delete retained replicas','themed button text must update, not only its input value');assert.equal($('#unm-retire-title').content,'Delete retained replicas');
assert.doesNotMatch(source,/\b(?:window\.)?(?:confirm|alert)\s*\(/);
const main=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const pick={replications:[]};vm.createContext(pick);vm.runInContext(main.slice(main.indexOf('function replicationFor('),main.indexOf('function recoveryPolicyForVm(')),pick);
pick.replications=[{id:'old',vmUuid:'u',peerId:'p',retirement:{state:'ENDED_KEEP'}}];assert.equal(pick.replicationFor('u','p'),null,'Ended rows must not hijack the VM Replicate button');
pick.replications.push({id:'new',vmUuid:'u',peerId:'p'});assert.equal(pick.replicationFor('u','p').id,'new','New active cycle is configured instead of retained history');pick.replications[1].retirement={state:'BLOCKED'};assert.equal(pick.replicationFor('u','p').id,'new','Unresolved ending is not silently treated as a fresh cycle');
pick.replications[0].retirement={state:'BLOCKED'};delete pick.replications[1].retirement;assert.equal(pick.replicationFor('u','p').id,'new','An older blocked cleanup does not steal the active-cycle Configure button');
console.log('Retirement dialog immediate loading, escaping, keep/delete, explicit partial discard, pinned retries, double-submit and late-response guards passed.');
