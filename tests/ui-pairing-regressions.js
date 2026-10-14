// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js','utf8');
const requests=[],elements=new Map(),flashes=[];
function $(key){
  if(!elements.has(key))elements.set(key,{value:'',content:'',props:{},classes:new Set(),
    val(v){if(v===undefined)return this.value;this.value=v;return this;},text(v){this.content=v;return this;},html(v){this.content=v;return this;},
    prop(k,v){this.props[k]=v;return this;},attr(){return this;},trigger(){return this;},addClass(k){this.classes.add(k);return this;},removeClass(k){this.classes.delete(k);return this;}});
  return elements.get(key);
}
const context={$,activePairing:null,pairingSerial:0,flash:msg=>flashes.push(msg),loadPeers:()=>({then(){}}),discover(){},
  post(action,data){const r={action,data,readyState:1,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},abort(){this.aborted=true;this.readyState=4;this.failure({});},resolve(data){this.readyState=4;this.success(data);},reject(message){this.readyState=4;this.failure({responseText:JSON.stringify({error:message})});}};requests.push(r);return r;}};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function esc('),source.indexOf('function fmtBytes(')),context);
vm.runInContext(source.slice(source.indexOf('function pair()'),source.indexOf('$(function()',source.indexOf('function pair()'))),context);
const fingerprint='SHA256:'+'a'.repeat(43);
const key={host:'192.0.2.2',port:2222,algorithm:'Ed25519',fingerprint};
$('#peer-host').val(key.host);$('#peer-port').val('2222');$('#peer-password').val('test-password');
context.pair();context.pair();
assert.equal(requests.length,1,'Repeated Pair clicks cannot overlap previews.');
assert.equal(requests[0].action,'pairingHostKey');assert.deepEqual(Object.keys(requests[0].data).sort(),['host','port'],'Preview never sends the password.');
assert.ok($('#unm-pair-modal').classes.has('open'));assert.equal($('#unm-pair-accept').props.disabled,true);
context.acceptPairing();assert.equal(requests.length,1,'Loading cannot accept or send a password.');
context.cancelPairing();assert.ok(requests[0].aborted);requests[0].success({hostKey:key});assert.equal(context.activePairing,null,'Late preview cannot resurrect a cancelled confirmation.');
context.pair();requests.at(-1).resolve({hostKey:key});
assert.match($('#unm-pair-content').content,/Verify manually \(optional\)/);assert.match($('#unm-pair-content').content,/<details>/);
assert.ok($('#unm-pair-content').content.includes(fingerprint));assert.equal($('#unm-pair-accept').props.disabled,false);
assert.equal(requests.filter(r=>r.action==='pairPeer').length,0,'Displaying a key is not consent to authenticate.');
context.acceptPairing();const pairRequest=requests.at(-1);context.acceptPairing();
assert.equal(requests.filter(r=>r.action==='pairPeer').length,1,'Acceptance submits only once.');
assert.deepEqual(JSON.parse(JSON.stringify(pairRequest.data)),{host:key.host,port:'2222',password:'test-password',host_fingerprint:fingerprint},'Submission uses the exact displayed endpoint and fingerprint.');
assert.equal($('#peer-password').val(),'','Password is cleared once submitted.');assert.equal(context.cancelPairing(),false,'Closing cannot abort an in-progress reciprocal mutation.');
pairRequest.reject('Host key changed. No password was sent.');assert.equal($('#unm-pair-accept').props.disabled,true);assert.equal($('#unm-pair-cancel').props.disabled,false);assert.match($('#unm-pair-content').content,/Host key changed/);context.cancelPairing();
$('#peer-password').val('retry-password');context.pair();requests.at(-1).resolve({hostKey:{...key,host:'wrong-host'}});
assert.equal($('#unm-pair-accept').props.disabled,true,'A response for a different endpoint cannot be accepted.');context.cancelPairing();
context.pair();requests.at(-1).reject('<img src=x onerror=alert(1)>');assert.ok(!$('#unm-pair-content').content.includes('<img'),'Remote errors are escaped.');context.cancelPairing();
context.pair();requests.at(-1).resolve({hostKey:key});context.acceptPairing();requests.at(-1).resolve({peer:{name:'Squid Proxy Host'}});
assert.equal(context.activePairing,null);assert.ok(!$('#unm-pair-modal').classes.has('open'));assert.match(flashes.at(-1),/Paired both ways/);
console.log('Pairing preview excludes credentials; explicit acceptance, cancellation, endpoint binding, changed keys, retries and response isolation passed.');
