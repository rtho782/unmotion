const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let prefilter,notices=0,events=0;
function $(){return {text(){return this;},prependTo(){notices++;return this;},trigger(){events++;return this;}};}
$.ajaxPrefilter=f=>prefilter=f;
const context={jQuery:$,window:{},document:{}};vm.createContext(context);vm.runInContext(fs.readFileSync(__dirname+'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/session.js','utf8'),context);
function request(status,text,url='/plugins/unmotion/api.php'){const r={status,responseText:text,always(f){this.finish=f;},abort(){this.aborted=true;}};prefilter({url},{},r);if(r.finish)r.finish();return r;}
request(500,'temporary error');assert.equal(context.window.unmSessionExpired,false);
request(200,'<html><form>Login password</form></html>');assert.equal(context.window.unmSessionExpired,true);assert.equal(notices,1);assert.equal(events,1);
assert.equal(request(0,'').aborted,true,'No further API request reaches the network after login expiry.');
assert.notEqual(request(0,'','/other/plugin.php').aborted,true,'Other plugins are unaffected.');
context.window.unmSessionExpired=false;request(403,'CSRF rejected');assert.equal(context.window.unmSessionExpired,true);
console.log('Session expiry, HTML login response, CSRF rejection and plugin-scoped request shutdown passed.');
