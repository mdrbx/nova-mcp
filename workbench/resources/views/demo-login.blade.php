<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nova MCP demo</title>
</head>
<body style="font: 18px system-ui; max-width: 560px; margin: 12vh auto; padding: 24px;">
    <h1>Nova MCP demo</h1>
    <p>Sign in as Alex Morgan to explore connections and consent with fictional records.</p>
    <form method="POST" action="{{ route('demo.signIn') }}">
        @csrf
        <button type="submit" style="font: inherit; padding: 10px 18px;">Open the local demo</button>
    </form>
</body>
</html>
