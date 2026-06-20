import argparse
import base64
import json
import os
import sys
import time
import urllib.parse
import urllib.request
import urllib.error

if 'GOOGLE_APPLICATION_CREDENTIALS' not in os.environ:
    candidate = os.path.join(os.path.dirname(__file__), '..', '..', 'storage', 'credentials.json')
    if os.path.isfile(candidate):
        os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = candidate

import google.auth
from google.auth.transport.requests import Request


def request_json(url, token, payload=None):
    data = None if payload is None else json.dumps(payload).encode('utf-8')
    request = urllib.request.Request(url, data=data, headers={
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json',
    }, method='POST' if payload is not None else 'GET')
    try:
        with urllib.request.urlopen(request, timeout=90) as response:
            return json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as error:
        detail = error.read().decode('utf-8', errors='replace')
        raise RuntimeError('HTTP %s at %s: %s' % (error.code, url, detail))


def find_gcs_uri(value):
    if isinstance(value, str) and value.startswith('gs://') and value.endswith('.mp4'):
        return value
    if isinstance(value, dict):
        for item in value.values():
            found = find_gcs_uri(item)
            if found:
                return found
    if isinstance(value, list):
        for item in value:
            found = find_gcs_uri(item)
            if found:
                return found
    return ''


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--project', required=True)
    parser.add_argument('--region', required=True)
    parser.add_argument('--model', required=True)
    parser.add_argument('--storage-uri', required=True)
    parser.add_argument('--prompt-file', required=True)
    parser.add_argument('--duration', type=int, required=True)
    parser.add_argument('--aspect-ratio', default='9:16')
    parser.add_argument('--resolution', default='1080p')
    parser.add_argument('--output', required=True)
    parser.add_argument('--image', default='')
    args = parser.parse_args()

    with open(args.prompt_file, encoding='utf-8') as prompt_file:
        prompt = prompt_file.read().strip()
    if not prompt:
        raise RuntimeError('Video prompt is empty.')

    credentials, _ = google.auth.default(scopes=['https://www.googleapis.com/auth/cloud-platform'])
    credentials.refresh(Request())
    token = credentials.token
    model = args.model if args.model.startswith('publishers/') else 'publishers/google/models/' + args.model
    base = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/%s' % (args.region, args.project, args.region, model)
    instance = {'prompt': prompt}
    if args.image:
        if not os.path.isfile(args.image):
            raise RuntimeError('Reference artwork image was not found: ' + args.image)
        extension = os.path.splitext(args.image)[1].lower()
        mime_type = 'image/png' if extension == '.png' else 'image/jpeg'
        with open(args.image, 'rb') as image_file:
            image_bytes = image_file.read()
        if not image_bytes:
            raise RuntimeError('Reference artwork image is empty: ' + args.image)
        instance['image'] = {'bytesBase64Encoded': base64.b64encode(image_bytes).decode('ascii'), 'mimeType': mime_type}
        print(json.dumps({'event': 'reference_image', 'path': os.path.basename(args.image), 'bytes': len(image_bytes)}), flush=True)
    payload = {'instances': [instance], 'parameters': {'storageUri': args.storage_uri, 'sampleCount': 1, 'resolution': args.resolution, 'aspectRatio': args.aspect_ratio, 'durationSeconds': args.duration}}
    operation = request_json(base + ':predictLongRunning', token, payload)
    operation_name = operation.get('name', '')
    if not operation_name:
        raise RuntimeError('Vertex did not return an operation name: ' + json.dumps(operation))
    print(json.dumps({'event': 'submitted', 'operation_name': operation_name}), flush=True)

    deadline = time.time() + 600
    operation_url = base + ':fetchPredictOperation'
    final_operation = None
    while time.time() < deadline:
        current = request_json(operation_url, token, {'operationName': operation_name})
        if current.get('done'):
            final_operation = current
            break
        print(json.dumps({'event': 'polling', 'operation_name': operation_name}), flush=True)
        time.sleep(12)
    if final_operation is None:
        raise RuntimeError('Operation timed out after 10 minutes: ' + operation_name)
    if final_operation.get('error'):
        raise RuntimeError('Vertex operation error: ' + json.dumps(final_operation['error']))
    uri = find_gcs_uri(final_operation)
    if not uri:
        raise RuntimeError('Operation finished without an MP4 gs:// URI: ' + json.dumps(final_operation))
    bucket_and_object = uri[5:].split('/', 1)
    if len(bucket_and_object) != 2:
        raise RuntimeError('Invalid GCS URI: ' + uri)
    bucket, object_name = bucket_and_object
    media_url = 'https://storage.googleapis.com/storage/v1/b/%s/o/%s?alt=media' % (urllib.parse.quote(bucket, safe=''), urllib.parse.quote(object_name, safe=''))
    request = urllib.request.Request(media_url, headers={'Authorization': 'Bearer ' + token})
    with urllib.request.urlopen(request, timeout=180) as response, open(args.output, 'wb') as output:
        output.write(response.read())
    print(json.dumps({'event': 'ready', 'operation_name': operation_name, 'video_uri': uri, 'output': args.output, 'bytes': os.path.getsize(args.output)}), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'event': 'error', 'error': str(error)}), flush=True)
        raise
