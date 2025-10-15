<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload PDF File</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center justify-content-center vh-100">

    <div class="card shadow p-4" style="width: 400px;">
        <h4 class="text-center mb-3"> Please upload your PDF file</h4>

        <form action="{{ route('upload.pdf') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="mb-3">
                <label for="pdf_file" class="form-label">Choose PDF file</label>
                <input type="file" class="form-control" id="pdf_file" name="pdf_file" accept="application/pdf" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">Upload</button>
        </form>
    </div>

</body>

</html>