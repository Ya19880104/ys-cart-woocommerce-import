// Execute actual shared API and three run-next consumers with finite API/UI boundaries.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/admin.js'), 'utf8');
function slice(start, end) {
    assert.equal(source.split(start).length, 2, 'unique source start');
    const first = source.indexOf(start), last = source.indexOf(end, first);
    assert.ok(last > first, 'source end exists');
    return source.slice(first, last);
}
const actual = slice('    const api =', '    const pause =')
    + slice('    const runNext = async', '    const showErrors =')
    + 'const wizard = {\n' + slice('        async runJobToEnd(', '        /* ── v0.7.0 訂單狀態選擇') + '\n};\n'
    + 'globalThis.subject = { api, runNext, autoRun, wizard, running: () => autoRunningJobId };';
let pass = 0, fail = 0;
function check(ok, label) { ok ? pass++ : fail++; process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${label}\n`); }
function fixture(receipt, jobStatus = 'running') {
    const calls = [], state = { status:'', pauses:0, reloads:0, progress:0 };
    const button = { disabled:false };
    const context = {
        window:{ wp:{ apiFetch:async (request) => {
            calls.push(request);
            if (/\/run-next$/.test(request.path)) {
                if (calls.filter(r => /\/run-next$/.test(r.path)).length > 1) { throw new Error('unexpected retry'); }
                return receipt;
            }
            if (request.path === '/unrelated/route') { return receipt; }
            return {id:41,status:jobStatus,processed_count:1};
        } } },
        apiPath:'/ys-cart-wc-import/v1', autoRunningJobId:null, statusLabels:{},
        setBusy:(node,busy) => { if(node) node.disabled=busy; },
        setStatus:message => { state.status=message; },
        loadJobs:async () => { ++state.reloads; },
        getJob:async id => context.window.wp.apiFetch({path:`/ys-cart-wc-import/v1/jobs/${id}`}),
        pause:async () => { ++state.pauses; throw new Error('unexpected pause before retry'); },
        isTerminal:status => ['completed','failed','cancelled'].includes(status),
        progressOf:() => 100,
    };
    vm.createContext(context); vm.runInContext(actual,context);
    context.subject.wizard.progress=() => { ++state.progress; };
    return { subject:context.subject, calls, state, button };
}
(async () => {
    let f=fixture({done:false,status:'reconciliation_required'});
    await f.subject.runNext(41,f.button);
    check(f.calls.length===1 && f.state.status.includes('暫停') && !f.state.status.includes('批次完成') && !f.button.disabled,
        'manual actual runNext displays a stop instead of completion');
    f=fixture({done:false,status:'reconciliation_required'});
    await f.subject.autoRun(41,f.button);
    check(f.calls.length===1 && f.state.pauses===0 && f.subject.running()===null && !f.button.disabled && f.state.status.includes('暫停'),
        'actual autoRun exits before reading status or retrying after the lock');
    f=fixture({done:false,status:'reconciliation_required'}); let stopped=false;
    try { await f.subject.wizard.runJobToEnd(41,'商品'); } catch(e) { stopped=e.message.includes('暫停'); }
    check(stopped && f.calls.length===1 && f.state.pauses===0 && f.state.progress===0,
        'actual wizard runJobToEnd propagates reconciliation without another row request');
    f=fixture({done:true,status:'completed'},'completed'); await f.subject.autoRun(41,f.button);
    check(f.calls.length===2 && f.state.pauses===0 && !f.state.status.includes('暫停'), 'ordinary completion still reaches existing terminal handling');
    f=fixture({status:'reconciliation_required'}); const other=await f.subject.api('/unrelated/route',{method:'POST'});
    check(other.status==='reconciliation_required', 'shared guard leaves unrelated routes unchanged');
    console.log(`Admin reconciliation stop: ${pass} PASS / ${fail} FAIL`);
    process.exitCode=fail?1:0;
})().catch(e=>{ console.error(e); process.exitCode=1; });
