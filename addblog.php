<?php
include 'admin/connect.php';
include 'admin/session.php';
include 'admin/header.php';
?>

<body class="vertical  light  ">
    <div class="wrapper">
        <?php
include 'admin/navbar.php';
include 'admin/aside.php';
?>

        <main role="main" class="main-content">
            <div class="container-fluid">


                <div class="card shadow mb-4">
                    <a href="dashboard.php">
                        <button type="button" class="btn btn-primary">Dashboard</button>
                    </a>
                    <div class="card-header">
                        <strong class="card-title">Add Blog</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12">
                                <form role="form" action="controller/_addfeedbacks.php" method="POST"
                                    enctype="multipart/form-data">
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Blog Title</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Blog Title" name="title">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Category Name</label>
                                        <select required type="file" id="simpleinput" class="form-control"
                                            placeholder="Certificate Id" name="category_id">
                                            <option value="">Choose Category</option>
                                                    <?php
          $sql1="Select * from categories";
          $results1=$connect->query($sql1);
          while($final1=$results1->fetch_assoc()){ ?>
                                                    <option value="<?php echo $final1['id'] ?>">
                                                        <?php echo $final1['name'] ?></option>
                                                    <?php } ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Short Description</label>
                                        <textarea required type="text" id="simpleinput" class="form-control"
                                            placeholder="Short Description" name="description"></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Main Content</label>
                                        <textarea class="form-control" name="content" id="editor"></textarea>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Banner Image</label>
                                        <input required type="file" id="simpleinput" class="form-control"
                                            placeholder="" name="banner_image">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Is Featured</label>
                                        <select required type="file" id="simpleinput" class="form-control"
                                            placeholder="Certificate Id" name="is_featured">
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <h5>SEO Settings</h5>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Blog Meta Title</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Meta Blog Title" name="meta_title">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Blog Meta Description</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Meta Blog Description" name="meta_description">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Blog Meta Keywords</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Meta Blog Keywords" name="keywords">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="simpleinput">Authur Name</label>
                                        <input required type="text" id="simpleinput" class="form-control"
                                            placeholder="Written By" name="created_by">
                                    </div>
                                    
                                    <div class="form-group mb-3">
                                        <input type="submit" id="example-palaceholder" class="btn btn-primary"
                                            value="Submit">
                                    </div>
                            </div> <!-- /.col -->
                            </form>
                        </div>
                    </div>
                    

                <script type="text/javascript" src='https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js'></script>
    <script>
    tinymce.init({
      selector: '#editor',
      plugins: 'anchor autolink charmap codesample emoticons image link lists media searchreplace table visualblocks wordcount checklist mediaembed casechange export formatpainter pageembed linkchecker a11ychecker tinymcespellchecker permanentpen powerpaste advtable advcode editimage tinycomments tableofcontents footnotes mergetags autocorrect typography inlinecss',
      toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table mergetags | addcomment showcomments | spellcheckdialog a11ycheck typography | align lineheight | checklist numlist bullist indent outdent | emoticons charmap | removeformat',
      tinycomments_mode: 'embedded',
      tinycomments_author: 'Sahil Gupta',
      mergetags_list: [
        { value: 'First.Name', title: 'First Name' },
        { value: 'Email', title: 'Email' },
      ]
    });
  </script>



            </div> <!-- .container-fluid -->

            <?php include "admin/footer.php"; ?>