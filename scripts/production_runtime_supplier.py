"""Loopback Epay/ZJMF and SMTP peers for the isolated production runtime acceptance.

These peers perform no requests to external systems. Requests retain only method/path;
SMTP retains message identifiers and recipient counts, never message bodies or addresses.
"""

import datetime
import email
import hashlib
import http.server
import re
import smtpd
import asyncore
import threading
import urllib.parse


class LoopbackSupplier:
    def __init__(self):
        self.state = {
            'settled': False, 'paid': False, 'gateway_order': None,
            'gateway_amount': None, 'cost': '8.00', 'status': 'Active',
            'selected': 501,
            'due': (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=30)).strftime('%Y-%m-%d %H:%M:%S'),
            'cancelled': False, 'paid_invoices': [],
        }
        self.requests = []
        self.errors = []
        self._lock = threading.RLock()
        self._server = None
        self._thread = None
        self.base_url = None

    def __enter__(self):
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.respond('GET', urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query))

            def do_POST(self):
                body = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
                self.respond('POST', urllib.parse.parse_qs(body, keep_blank_values=True))

            def respond(self, method, query):
                path = urllib.parse.urlsplit(self.path).path
                params = {key: value[0] for key, value in query.items()}
                with owner._lock:
                    owner.requests.append({'method': method, 'path': path})
                    try:
                        result = owner._respond(path, params, self.headers)
                        status = 200
                    except Exception as error:
                        owner.errors.append({'path': path, 'error': type(error).__name__, 'message': str(error)})
                        result = {'status': 500, 'message': 'Loopback supplier assertion failed'}
                        status = 500
                import json
                output = json.dumps(result).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(output)))
                self.end_headers()
                self.wfile.write(output)

            def log_message(self, *args):
                pass

        self._server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.base_url = f'http://127.0.0.1:{self._server.server_port}'
        self._thread = threading.Thread(target=self._server.serve_forever, daemon=True)
        self._thread.start()
        return self

    def _respond(self, path, params, headers):
        state = self.state
        if path == '/mapi.php':
            assert params['pid'] == 'test-merchant', 'Unexpected merchant'
            assert params['type'] == 'wxpay', 'Unexpected gateway channel'
            assert params['money'] in ['9.60', '2.40'], 'Unexpected payment amount'
            notify = urllib.parse.urlsplit(params['notify_url'])
            assert notify.scheme == 'https' and notify.hostname == '127.0.0.1', 'Callback left the HTTPS loopback site'
            assert notify.path == '/api/payments/epay_wechat/notify', 'Unexpected callback path'
            signed = {key: value for key, value in params.items() if key not in ['sign', 'sign_type'] and value != ''}
            digest = hashlib.md5(('&'.join(key + '=' + signed[key] for key in sorted(signed)) + 'epay-test-secret').encode()).hexdigest()
            assert params['sign'].lower() == digest, 'Gateway charge signature mismatch'
            state['gateway_order'] = params['out_trade_no']
            state['gateway_amount'] = params['money']
            return {'code': 1, 'payurl': self.base_url + '/simulated-payment'}
        if path == '/zjmf_api_login':
            assert params['password'] == 'supplier-api-secret', 'Unexpected upstream credentials'
            return {'status': 200, 'jwt': 'supplier-private-jwt'}
        assert headers.get('Authorization') == 'Bearer supplier-private-jwt', 'Missing upstream authentication'
        if path == '/cart/get_product_config':
            return {'status': 200, 'data': {
                'products': {'id': 17, 'name': 'Acceptance Cloud', 'location_version': 1,
                             'password': {'show': 1, 'rule': {'upper': 1, 'lower': 1, 'num': 1, 'special': 1}},
                             'host': {'show': 0}},
                'product_pricings': [{'code': 'CNY', 'monthly': '7.00', 'msetupfee': '0.00'}],
                'config_groups': [{'id': 10, 'options': [{'id': 50, 'option_name': 'CPU', 'option_type': 1, 'upgrade': 1,
                    'sub': [{'id': 501, 'option_name': 'Small', 'pricings': [{'code': 'CNY', 'monthly': '1.00', 'msetupfee': '0.00'}]},
                            {'id': 502, 'option_name': 'Large', 'pricings': [{'code': 'CNY', 'monthly': '3.00', 'msetupfee': '0.00'}]}]}]}],
                'customfields': [{'id': 80, 'fieldname': 'License', 'fieldtype': 'password', 'required': 1}], 'advanced': []}}
        if path == '/user_info':
            return {'status': 200, 'data': {'user': {'currency': 1}}}
        if path == '/api/product/proinfo':
            return {'status': 200, 'data': {'info': [{'id': 17, 'location_version': 1, 'stock_control': 1, 'qty': 5}]}}
        if path == '/cart/stock_control':
            return {'status': 200, 'data': {'product': {'hidden': 0, 'stock_control': 1, 'qty': 5}}}
        if path == '/cart/get_total':
            return {'status': 200, 'products': {'total': '10.00' if params.get('configoption[50]') == '502' else '8.00',
                                             'setupfee_total': '0.00'}, 'currency': {'id': 1, 'code': 'CNY'}}
        if path == '/cart/clear':
            return {'status': 400, 'domainstatus': 'Active', 'hostid': 999} if state['paid'] else {'status': 200}
        if path == '/cart/add_to_shop':
            assert params['currencyid'] == params['currecncyid'] == '1', 'Upstream currency spelling/mapping changed'
            password = params.get('password', '')
            assert len(password) >= 12 and all(re.search(pattern, password) for pattern in [r'[A-Z]', r'[a-z]', r'\d', r'[^A-Za-z0-9]']), 'Generated provisioning password failed configured requirements'
            assert params.get('host'), 'Generated hostname was empty'
            return {'status': 200}
        if path == '/cart/settle':
            assert not state['settled'], 'Duplicate upstream order'
            assert params['cart_data[pid]'] == '17', 'Unexpected upstream product'
            state['settled'] = True
            return {'status': 200, 'data': {'invoiceid': 42, 'hostid': [999]}}
        if path == '/use_credit_page':
            assert params['invoiceid'] in ['42', '43', '44'], 'Unknown upstream payable invoice'
            return {'status': 200, 'data': {'total': '2.00' if params['invoiceid'] == '44' else state['cost'],
                                            'currency': {'code': 'CNY'}}}
        if path == '/apply_credit':
            assert params['use_credit'] == '1', 'Unexpected upstream credit operation'
            invoice = params['invoiceid']
            assert invoice in ['42', '43', '44'], 'Unknown upstream payment invoice'
            assert invoice not in state['paid_invoices'], 'Duplicate upstream debit'
            if invoice == '42':
                assert state['settled'] and not state['paid'], 'Purchase invoice was not prepared once'
            elif invoice == '43':
                assert state['paid'], 'Renewal preceded original delivery'
                due = datetime.datetime.strptime(state['due'], '%Y-%m-%d %H:%M:%S')
                state['due'] = (due + datetime.timedelta(days=31)).strftime('%Y-%m-%d %H:%M:%S')
            else:
                assert state['paid'], 'Upgrade preceded original delivery'
                state['selected'] = 502
                state['cost'] = '10.00'
            state['paid_invoices'].append(invoice)
            state['paid'] = True
            return {'status': 1001, 'data': {'hostid': [999]}}
        if path == '/host/header':
            assert state['paid'], 'Host read preceded upstream payment'
            return {'status': 200, 'data': {'host_data': {
                'domainstatus': state['status'], 'nextduedate': state['due'], 'productid': 17,
                'billingcycle': 'monthly', 'dedicatedip': '192.0.2.17',
                'username': 'root', 'password': 'DELIVERED-HOST-PASSWORD'}}}
        if path == '/host/renewpage':
            return {'status': 200, 'data': {'host': {'amount': state['cost'], 'billingcycle': 'monthly'},
                                            'currency': {'code': 'CNY'}}}
        if path == '/host/renew':
            assert sum(item['path'] == path for item in self.requests) == 1, 'Duplicate upstream renewal'
            return {'status': 200, 'data': {'invoiceid': 43}}
        if path == '/upgrade/upgrade_config_post':
            assert params['configoption[50]'] == '502', 'Unexpected upgrade target'
            return {'status': 200}
        if path == '/upgrade/upgrade_config_page':
            return {'status': 200, 'data': {'total': '2.00', 'currency': {'code': 'CNY'}}}
        if path == '/upgrade/checkout_config_upgrade':
            assert sum(item['path'] == path for item in self.requests) == 1, 'Duplicate upstream upgrade checkout'
            return {'status': 200, 'data': {'invoiceid': 44}}
        if path == '/upgrade/index/999':
            return {'status': 200, 'data': {'host': [{'oid': 50, 'subid': state['selected'], 'option_type': 1, 'qty': 1}]}}
        if path == '/host/cancel':
            state['cancelled'] = True
            return {'status': 200}
        if path == '/host/setdownstream':
            return {'status': 200}
        raise AssertionError('Unexpected supplier path ' + path)

    def __exit__(self, *args):
        if self._server is not None:
            self._server.shutdown()
            self._server.server_close()
        if self._thread is not None:
            self._thread.join(timeout=3)


class LoopbackMail:
    """A real local SMTP receiver; receipt is not external mail delivery evidence."""

    def __init__(self):
        self.host = '127.0.0.1'
        self.port = None
        self.messages = []
        self._map = {}
        self._stopped = threading.Event()
        self._server = None
        self._thread = None

    def __enter__(self):
        owner = self

        class Sink(smtpd.SMTPServer):
            def process_message(self, peer, mailfrom, rcpttos, data, **kwargs):
                message = email.message_from_bytes(data)
                owner.messages.append({'message_id': message.get('Message-ID'), 'recipient_count': len(rcpttos)})

        self._server = Sink((self.host, 0), None, map=self._map, decode_data=False)
        self.port = self._server.socket.getsockname()[1]

        def receive():
            while not self._stopped.is_set():
                asyncore.loop(timeout=.1, count=1, map=self._map)

        self._thread = threading.Thread(target=receive, daemon=True)
        self._thread.start()
        return self

    def __exit__(self, *args):
        self._stopped.set()
        if self._thread is not None:
            self._thread.join(timeout=3)
        for channel in list(self._map.values()):
            channel.close()
