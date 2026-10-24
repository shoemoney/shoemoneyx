#!/usr/bin/env python3
"""Run the complete boot harness against local executables, without AWS or networking."""
import json
from pathlib import Path
import subprocess
import shutil
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).with_name('test-boot.sh').resolve()
STUB = r'''
import json, os, pathlib, shlex, stat, sys
name=pathlib.Path(sys.argv[0]).name
args=sys.argv[1:]
case=os.environ['BOOT_CASE']
with open(os.environ['BOOT_TRACE'], 'a') as f: f.write(json.dumps([name,args])+'\n')
def error(message):
 with open(os.environ['BOOT_TRACE'], 'a') as f: f.write(json.dumps(['ERROR',[message]])+'\n')
 sys.exit(91)
if name=='aws':
 for key,val in [('describe-vpcs','vpc-test'),('describe-instance-type-offerings','us-east-1a'),('describe-subnets','subnet-test'),('create-security-group','sg-test'),('run-instances','i-test'),('describe-instances','203.0.113.7')]:
  if key in args: print(val); break
 else:
  if not any(key in args for key in ['authorize-security-group-ingress','wait','terminate-instances','delete-security-group']): error('unsupported aws command')
elif name=='openssl':
 if 'rand' in args: print('synthetic-owner-password-0123456789abcdef')
 elif 'x509' in args:
  print("issuer=Let's Encrypt" if case!='issuer' else 'issuer=Other')
  print('IP Address:203.0.113.'+('8' if case=='san' else '7'))
 elif 's_client' in args: print('synthetic certificate')
 else: error('unsupported openssl command')
elif name=='ssh':
 cmd=args[-1]
 if 'credentials.txt' in cmd: print('MASTER_PASSWORD=synthetic-bootstrap')
 elif 'timedatectl' in cmd: print('yes')
 elif 'chronyc' in cmd: print('169.254.169.123')
 elif ' ps ' in cmd: print('{"Service":"web","Health":"healthy"}\n{"Service":"nginx","State":"running"}')
 elif 'exec -T web' in cmd: print('200\n000blocked')
 elif 'ufw status' in cmd: print('443/tcp ALLOW')
 elif 'systemctl is-active --quiet shoemoneyx-firewall.service' in cmd: pass
 else: error('unsupported ssh command')
elif name=='nmap': print('22/tcp open ssh\n443/tcp open https')
elif name=='curl':
 url=args[-1]
 i=0
 while i<len(args)-1:
  flag=args[i]
  if flag in ['--config','-m','-o','-w','-H','--data-binary']:
   if i+1>=len(args)-1: error('missing curl option value')
   i+=2
  elif flag in ['-k','-s','--fail']: i+=1
  else: error('unsupported curl flag '+flag)
 if url=='http://203.0.113.7/': sys.exit(7)
 if not url.startswith('https://203.0.113.7/'): error('unsupported curl URL')
 if '/login' in url:
  if case=='ca': sys.exit(60)
  if '-o' not in args: print('<div id="first-login-guide"></div>' if case!='banner' else '<div id="tls-warning"></div>')
 elif '--config' in args:
  config=pathlib.Path(args[args.index('--config')+1])
  assert stat.S_IMODE(config.stat().st_mode)==0o600
  assert stat.S_IMODE(config.parent.stat().st_mode)==0o700
  token=shlex.split(config.read_text())[2].split(': ',1)[1]
  assert token not in ' '.join(args)
  assert not any(a in args for a in ['-k','--insecure'])
  assert 'synthetic-owner-password' not in ' '.join(args)
  if case=='late': sys.exit(60)
  state=config.parent/'claimed'
  code=200; body='{}'
  if '--data-binary' in args:
   if not url.endswith('/api/onboarding/master-password'): error('unsupported claim endpoint')
   payload=pathlib.Path(args[args.index('--data-binary')+1][1:])
   assert stat.S_IMODE(payload.stat().st_mode)==0o600
   data=json.loads(payload.read_text())
   assert data['password']==data['password_confirmation']
   assert 12<=len(data['password'].encode())<=72
   if case=='claim': code=422
   else: state.touch()
  elif token=='synthetic-bootstrap':
   if state.exists(): code=200 if case=='stale' else 401
   elif url.endswith('/api/status'): code=403; body='{"error":"set_password_required"}'
   elif not url.endswith('/api/onboarding'): error('unsupported bootstrap endpoint')
  else:
   if not url.endswith('/api/status'): error('unsupported owner endpoint')
   body=json.dumps({'health':{'mode':'live' if case=='mode' else 'paper'}})
  pathlib.Path(args[args.index('-o')+1]).write_text(body)
  print(code,end='')
 elif url.endswith('/api/status') and '-k' in args: pass
 else: error('unsupported curl request')
else: error('unsupported executable')
'''

class BootHarnessTest(unittest.TestCase):
    def run_case(self, case, script_text=None):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            stub = root / 'stub'
            stub.write_text('#!'+sys.executable+'\n'+STUB)
            stub.chmod(0o755)
            for command in ['aws', 'ssh', 'curl', 'openssl', 'nmap']:
                (root / command).symlink_to(stub)
            for command in ['bash','cat','grep','cut','seq','date','mktemp','rm','jq','sed','awk','sort','tr']:
                executable = shutil.which(command)
                self.assertIsNotNone(executable, command)
                (root / command).symlink_to(executable)
            target = SCRIPT
            if script_text is not None:
                target = root / 'mutated-test-boot.sh'
                target.write_text(script_text)
            trace = root / 'trace'
            env = dict(PATH=str(root), HOME=str(root), TMPDIR=str(root), BOOT_CASE=case, BOOT_TRACE=str(trace))
            result = subprocess.run([str(root/'bash'), '-c', 'sleep(){ SECONDS=$((SECONDS+200)); }; export -f sleep; source "$1" ami-local', 'test', str(target)], env=env, capture_output=True, text=True, timeout=10)
            calls = [json.loads(line) for line in trace.read_text().splitlines()]
            self.assertFalse([args for name,args in calls if name=='ERROR'], result.stderr)
            self.assertTrue(any(name=='aws' and 'terminate-instances' in args for name,args in calls))
            self.assertTrue(any(name=='aws' and 'delete-security-group' in args for name,args in calls))
            # Check while the sandbox still exists: its context manager must not supply
            # the cleanup that the boot harness itself is required to perform.
            auth_dirs = {Path(args[args.index('--config')+1]).parent
                         for name,args in calls if name=='curl' and '--config' in args}
            for directory in auth_dirs:
                self.assertFalse(directory.exists(), 'private auth directory left behind')
                for filename in ['bootstrap.curl', 'owner.curl', 'claim.json', 'body']:
                    self.assertFalse((directory/filename).exists(), 'private auth file left behind: '+filename)
            return result, calls

    def test_failed_public_trust_never_reads_credentials(self):
        for case in ['ca', 'issuer', 'san', 'banner']:
            with self.subTest(case=case):
                result, calls = self.run_case(case)
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertFalse(any(name=='ssh' and 'credentials.txt' in args[-1] for name,args in calls))
                self.assertTrue(any(name=='aws' and 'terminate-instances' in args for name,args in calls))
                self.assertTrue(any(name=='aws' and 'delete-security-group' in args for name,args in calls))

    def test_owner_lifecycle_and_private_requests(self):
        for case in ['happy', 'late', 'claim', 'mode', 'stale']:
            with self.subTest(case=case):
                result, calls = self.run_case(case)
                self.assertEqual(result.returncode, 0 if case=='happy' else 1, result.stderr)
                self.assertNotIn('synthetic-bootstrap', result.stderr+result.stdout)
                self.assertNotIn('synthetic-owner-password', result.stderr+result.stdout)
                auth = [args for name,args in calls if name=='curl' and '--config' in args]
                self.assertEqual(len(auth), 5 if case in ['happy','stale'] else 1 if case=='late' else 3 if case=='claim' else 4)


    def test_cleanup_assertion_rejects_missing_cleanup(self):
        original = SCRIPT.read_text()
        cleanup = 'rm -rf -- "$AUTH_DIR"'
        self.assertEqual(original.count(cleanup), 2, 'negative control must disable both cleanup paths')
        mutated = original.replace(cleanup, ': # synthetic negative control disables secret cleanup')
        with self.assertRaisesRegex(AssertionError, 'private auth directory left behind'):
            self.run_case('happy', script_text=mutated)

if __name__ == '__main__':
    unittest.main()
