<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DevOps Console | Dashboard</title>
  @include('admin.layouts.header')
  @stack('css')
  <!-- Google tag (gtag.js) -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-59Z9WL79QD"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-59Z9WL79QD');
  </script>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
  @include('admin.layouts.nav')
  @include('admin.layouts.sidebar')
  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    @yield('content')
    <!-- /.content -->
  </div>
  <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->
  @include('admin.layouts.scripts')
  @stack('js')
  </body>
</html>
